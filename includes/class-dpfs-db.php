<?php
/**
 * Payments data store.
 *
 * @package Demfilz_Payment_Forms_for_Stripe
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the custom payments table and its CRUD operations.
 *
 * Every query targets this plugin's own custom table and is run through
 * $wpdb->prepare(): the table name uses the %i identifier placeholder (WP 6.2+)
 * and all values use %s / %d placeholders, so nothing is interpolated unescaped.
 * The object cache is deliberately not used because payment records must be read
 * in real time, so the direct-query sniffs are disabled for the class. The table
 * name is a trusted constant derived from $wpdb->prefix and is bound with the %i
 * identifier placeholder, so the Plugin Check unescaped-parameter sniff is too.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */
class DPFS_DB {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dpfs_payments';
	}

	/**
	 * Create / upgrade the payments table.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			session_id VARCHAR(255) NOT NULL DEFAULT '',
			payment_intent VARCHAR(255) NOT NULL DEFAULT '',
			name VARCHAR(255) NOT NULL DEFAULT '',
			email VARCHAR(255) NOT NULL DEFAULT '',
			phone VARCHAR(100) NOT NULL DEFAULT '',
			reference VARCHAR(255) NOT NULL DEFAULT '',
			message TEXT NULL,
			amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'usd',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a new payment record.
	 *
	 * @param array $data Column values.
	 * @return int Insert id.
	 */
	public static function insert( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = wp_parse_args(
			$data,
			array(
				'created_at'     => $now,
				'updated_at'     => $now,
				'session_id'     => '',
				'payment_intent' => '',
				'name'           => '',
				'email'          => '',
				'phone'          => '',
				'reference'      => '',
				'message'        => '',
				'amount'         => 0,
				'currency'       => 'usd',
				'status'         => 'pending',
			)
		);
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a payment record.
	 *
	 * @param int   $id   Record id.
	 * @param array $data Column values.
	 * @return int|false
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Get a record by id.
	 *
	 * @param int $id Record id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), (int) $id ) );
	}

	/**
	 * Get a record by Stripe session id.
	 *
	 * @param string $session_id Checkout session id.
	 * @return object|null
	 */
	public static function get_by_session( $session_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE session_id = %s', self::table(), $session_id ) );
	}

	/**
	 * Query records for the admin list (newest first).
	 *
	 * @param array $args { status, per_page, page }.
	 * @return array
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$table    = self::table();
		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		if ( $args['status'] ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d',
					$table,
					$args['status'],
					$per_page,
					$offset
				)
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
				$table,
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Count records, optionally filtered by status.
	 *
	 * @param string $status Status filter.
	 * @return int
	 */
	public static function count( $status = '' ) {
		global $wpdb;
		if ( $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::table(), $status ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}

	/**
	 * Aggregate stats for the dashboard.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$table = self::table();

		$stats = array(
			'paid_count'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, 'paid' ) ),
			'pending_count' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', $table, 'pending' ) ),
			'total_count'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ),
			'by_currency'   => array(),
			'month_by_curr' => array(),
		);

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT currency, SUM(amount) AS total FROM %i WHERE status = %s GROUP BY currency', $table, 'paid' ) );
		foreach ( (array) $rows as $r ) {
			$stats['by_currency'][ $r->currency ] = (int) $r->total;
		}

		$month = current_time( 'Y-m' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT currency, SUM(amount) AS total FROM %i WHERE status = %s AND DATE_FORMAT(created_at, %s) = %s GROUP BY currency',
				$table,
				'paid',
				'%Y-%m',
				$month
			)
		);
		foreach ( (array) $rows as $r ) {
			$stats['month_by_curr'][ $r->currency ] = (int) $r->total;
		}

		return $stats;
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
