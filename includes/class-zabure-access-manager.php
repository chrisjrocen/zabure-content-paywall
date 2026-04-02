<?php
/**
 * Access Manager for Zabure Content Paywall.
 *
 * Handles granting, revoking, and checking user access to premium posts.
 * Access is stored permanently in wp_usermeta with no expiry.
 *
 * @package ZabureContentPaywall
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Zabure_Access_Manager
 */
class Zabure_Access_Manager {

	/**
	 * The user meta key that stores the array of paid post IDs.
	 */
	const META_KEY = '_zabure_paid_posts';

	/**
	 * Check whether a user has paid for a specific post.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @param int $post_id The WordPress post ID.
	 * @return bool True if the user has access.
	 */
	public function has_access( int $user_id, int $post_id ): bool {
		if ( ! $user_id || ! $post_id ) {
			return false;
		}

		$paid_posts = $this->get_user_paid_posts( $user_id );

		return in_array( $post_id, $paid_posts, true );
	}

	/**
	 * Grant a user permanent access to a post.
	 *
	 * Updates the user meta array, marks the session as completed,
	 * and fires the zabure_access_granted action hook.
	 *
	 * @param int    $user_id    The WordPress user ID.
	 * @param int    $post_id    The WordPress post ID.
	 * @param int    $session_id The session row ID (0 for manual/synthetic grants).
	 * @param string $source     How access was granted: 'redirect', 'webhook', or 'manual'.
	 * @return void
	 */
	public function grant_access( int $user_id, int $post_id, int $session_id, string $source ): void {
		if ( ! $user_id || ! $post_id ) {
			return;
		}

		// Get current list of paid posts for this user, avoiding duplicates.
		$paid_posts = $this->get_user_paid_posts( $user_id );

		if ( ! in_array( $post_id, $paid_posts, true ) ) {
			$paid_posts[] = $post_id;
			update_user_meta( $user_id, self::META_KEY, $paid_posts );
		}

		// Update the session to completed.
		if ( $session_id > 0 ) {
			Zabure_Database::update_session_status(
				$session_id,
				'completed',
				[
					'completed_at' => current_time( 'mysql' ),
					'source'       => $source,
				]
			);
		}

		/**
		 * Fires after a user is granted access to a post.
		 *
		 * @param int    $user_id The user ID.
		 * @param int    $post_id The post ID.
		 * @param string $source  How access was granted.
		 */
		do_action( 'zabure_access_granted', $user_id, $post_id, $source );
	}

	/**
	 * Revoke a user's access to a post.
	 *
	 * Removes the post ID from the user's paid posts meta array.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @param int $post_id The WordPress post ID.
	 * @return void
	 */
	public function revoke_access( int $user_id, int $post_id ): void {
		if ( ! $user_id || ! $post_id ) {
			return;
		}

		$paid_posts = $this->get_user_paid_posts( $user_id );
		$paid_posts = array_values( array_filter( $paid_posts, fn( $id ) => (int) $id !== $post_id ) );

		update_user_meta( $user_id, self::META_KEY, $paid_posts );

		/**
		 * Fires after a user's access to a post is revoked.
		 *
		 * @param int $user_id The user ID.
		 * @param int $post_id The post ID.
		 */
		do_action( 'zabure_access_revoked', $user_id, $post_id );
	}

	/**
	 * Get all post IDs a user has paid for.
	 *
	 * @param int $user_id The WordPress user ID.
	 * @return int[] Array of post IDs.
	 */
	public function get_user_paid_posts( int $user_id ): array {
		$meta = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $meta ) ) {
			return [];
		}

		return array_map( 'intval', $meta );
	}

	/**
	 * Get all users who have access to a specific post.
	 *
	 * @param int $post_id The WordPress post ID.
	 * @return WP_User[] Array of WP_User objects.
	 */
	public function get_users_with_access( int $post_id ): array {
		$users_with_meta = get_users(
			[
				'meta_key'   => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'compare'    => 'EXISTS',
				'number'     => 500,
			]
		);

		return array_filter(
			$users_with_meta,
			fn( WP_User $u ) => $this->has_access( $u->ID, $post_id )
		);
	}
}
