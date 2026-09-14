<?php
/**
 * WHAT IS THIS FILE?
 *
 * The clock. It arranges for the background worker to run every few minutes.
 *
 * WHY DOES IT EXIST?
 *
 * Because the worker has to run without anyone asking it to, and WP-Cron is how
 * WordPress does that. This file is deliberately thin: it schedules, it locks, it
 * calls the worker. All the actual work is in class-worker.php.
 *
 * HOW WP-CRON REALLY WORKS, AND WHY IT MATTERS HERE
 *
 * WP-Cron is not cron. Nothing on the server wakes WordPress up. Instead, on each
 * page load WordPress checks whether anything is overdue and, if so, fires off a
 * request to itself to run it.
 *
 * Two consequences shape this file:
 *
 *   1. A SITE WITH NO VISITORS DOES NO WORK. On a quiet network the queue can sit
 *      still for hours. That is not a bug in the plugin, and the dashboard says so
 *      plainly — along with the one line of real cron that fixes it.
 *
 *   2. TICKS CAN OVERLAP. Two visitors arriving together can trigger two runs at
 *      once. That is normal, not exotic. Two defences: the lock below stops the
 *      common case cheaply, and claim_next() in class-documents.php makes the
 *      uncommon case harmless anyway. Belt and braces, because the cost of getting
 *      this wrong is uploading — and being billed for — the same document twice.
 *
 * ON MULTISITE, THIS RUNS ON THE MAIN SITE ONLY
 *
 * The queue is one network-wide table, so one clock is enough. WP-Cron events are
 * per-site, so scheduling on every site in a 200-site network would give us 200
 * clocks all working the same queue — 200 times the overhead for exactly the same
 * throughput.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Equalify_Iris_Scheduler {

	/** The cron hook name. */
	const HOOK = 'equalify_iris_tick';

	/** Our custom schedule's name. */
	const SCHEDULE = 'equalify_iris_five_minutes';

	/** How often a tick should run, in seconds. */
	const INTERVAL = 300;

	/**
	 * How long the "a tick is already running" lock lasts, in seconds.
	 *
	 * Comfortably longer than a tick's own budget, so a tick cannot be running
	 * while its lock has quietly expired — but short enough that a tick killed
	 * mid-run (a PHP timeout, a restart) does not block the queue for long.
	 */
	const LOCK_DURATION = 120;

	private Equalify_Iris_Worker $worker;

	public function __construct( Equalify_Iris_Worker $worker ) {
		$this->worker = $worker;
	}

	public function init(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK, array( $this, 'run_tick' ) );
	}

	/**
	 * Teach WordPress about a five-minute interval.
	 *
	 * WordPress ships with hourly, twice daily and daily, and nothing shorter than
	 * an hour. Hourly would mean a queue of 5,000 documents took months.
	 *
	 * WHY FIVE MINUTES AND NOT ONE?
	 *
	 * Because Iris converts two documents at a time and each takes minutes. Ticking
	 * every minute would mean four out of five ticks finding nothing to do and
	 * spending a PHP process to discover that. Five minutes keeps us close to the
	 * pace Iris can actually work at.
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every five minutes (Equalify Iris)', 'equalify-iris' ),
		);

		return $schedules;
	}

	/**
	 * Make sure the tick is scheduled. Safe to call on every page load.
	 */
	public static function schedule(): void {
		if ( ! is_main_site() ) {
			return;
		}

		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
	}

	/**
	 * Stop the tick from running.
	 *
	 * Called on deactivation. Not called when an admin presses Stop — the tick
	 * keeps being scheduled and simply returns early, so that pressing Start again
	 * resumes within five minutes instead of waiting for a fresh schedule.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** When the next tick is due, or 0 if none is scheduled. */
	public static function next_run(): int {
		return (int) wp_next_scheduled( self::HOOK );
	}

	/**
	 * Is WP-Cron turned off on this install?
	 *
	 * Some hosts set DISABLE_WP_CRON and run real cron instead, which is the better
	 * setup. Others set it and forget the second half, which means nothing ever
	 * runs. The dashboard needs to be able to tell an admin which situation they are
	 * in, because from inside WordPress the two look identical.
	 */
	public static function wp_cron_is_disabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * Run one tick, unless another one is already running.
	 */
	public function run_tick(): array {
		if ( ! $this->lock() ) {
			return array(
				'ran'    => false,
				'reason' => 'locked',
			);
		}

		try {
			return $this->worker->run();
		} finally {
			// `finally` so the lock is always released, even if the worker throws.
			// Without it, one unexpected error would leave the lock in place and
			// stall the queue for its full duration.
			$this->unlock();
		}
	}

	/**
	 * Run a tick right now, ignoring the schedule.
	 *
	 * Used by WP-CLI and by the "Run now" button. Still takes the lock, because
	 * running by hand while a scheduled tick is in progress is exactly the overlap
	 * we are guarding against.
	 */
	public function run_now(): array {
		return $this->run_tick();
	}

	// -----------------------------------------------------------------------
	// The lock
	// -----------------------------------------------------------------------

	/**
	 * Try to take the lock.
	 *
	 * A network transient with an expiry, not a database row, because the expiry is
	 * the important part: whatever happens to the process holding it — timeout,
	 * fatal error, someone pulling a power cable — the lock lets go by itself.
	 *
	 * This is a best-effort lock, not a guarantee. Two processes reading and setting
	 * a transient at the same instant can both believe they won. That is fine, and
	 * saying so here is more useful than pretending otherwise: the real protection
	 * against double work is claim_next(), which the database enforces. This lock
	 * exists to make the common case cheap, not to be the only defence.
	 */
	private function lock(): bool {
		if ( get_site_transient( self::HOOK . '_lock' ) ) {
			return false;
		}

		set_site_transient( self::HOOK . '_lock', time(), self::LOCK_DURATION );

		return true;
	}

	/** Release the lock. */
	private function unlock(): void {
		delete_site_transient( self::HOOK . '_lock' );
	}

	/** Is a tick running right now? Shown on the dashboard. */
	public static function is_locked(): bool {
		return (bool) get_site_transient( self::HOOK . '_lock' );
	}
}
