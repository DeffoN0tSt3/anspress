<?php
/**
 * Handle all function related to voting system.
 *
 * @link https://anspress.io
 */

/**
 * AnsPress vote related class.
 */
class AnsPress_Vote
{
	/**
	 * Process voting button.
	 * @since 2.0.1.1
	 */
	public static function vote() {
	    $post_id = (int) ap_sanitize_unslash( 'post_id', 'request' );

	    if ( ! ap_verify_nonce( 'vote_'.$post_id ) ) {
	        ap_ajax_json('something_wrong' );
	    }

	    $type = ap_sanitize_unslash( 'type', 'request' );
	    $type = ($type == 'up' ? 'vote_up' : 'vote_down');

	    $userid = get_current_user_id();

	    $post = ap_get_post( $post_id );

	    $thing = ap_user_can_vote_on_post( $post_id, $type, $userid, true );

	    // Check if WP_Error object and send error message code.
	    if ( is_wp_error( $thing ) ) {
	        ap_ajax_json( $thing->get_error_code() );
	    }

	    if ( 'question' == $post->post_type && ap_opt( 'disable_down_vote_on_question' ) && 'vote_down' == $type ) {
	        ap_ajax_json( 'voting_down_disabled' );
	    } elseif ( 'answer' === $post->post_type && ap_opt( 'disable_down_vote_on_answer' ) && 'vote_down' === $type ) {
	        ap_ajax_json( 'voting_down_disabled' );
	    }

	    $is_voted = ap_is_user_voted( $post_id, 'vote', $userid );

	    if ( is_object( $is_voted ) && $is_voted->count > 0 ) {
	        // If user already voted and click that again then reverse.
			if ( $is_voted->type == $type ) {
			    $row = ap_remove_vote($type, $userid, $post_id, $post->post_author );

				// Update post meta.
				$counts = ap_update_votes_count( $post_id );

			    do_action( 'ap_undo_vote', $post_id, $counts );
			    do_action( 'ap_undo_'.$type, $post_id, $counts );

			   	ap_ajax_json( array(
			   		'action' 	=> 'undo',
			   		'type' 		=> $type,
			   		'count' 	=> $counts['votes_net'],
			   		'message' 	=> 'undo_vote',
			   	) );
			}

			// Else ask user to undor their vote first.
			ap_ajax_json( 'undo_vote_your_vote' );
	    }

		$counts = ap_add_post_vote( $userid, $type, $post_id, $post->post_author );

		// Update post meta.
		do_action( 'ap_'.$type, $post_id, $counts );

	   	ap_ajax_json( array( 
	   		'action' => 'voted', 
	   		'type' => $type, 
	   		'count' => $counts['votes_net'], 
	   		'message' => 'voted' 
	   	) );
	}
}

/**
 * Add vote meta.
 *
 * @param int    $current_userid    User ID of user casting the vote
 * @param string $type              Type of vote, "vote_up" or "vote_down"
 * @param int    $actionid          Post ID
 * @param int    $receiving_userid User ID of user receiving the vote. @since 2.3
 *
 * @return integer
 */
function ap_add_vote( $current_userid, $type, $actionid, $receiving_userid, $count = 1 ) {
	// Snaitize varaiables.
	$current_userid = (int) $current_userid;
	$type = sanitize_title_for_query( $type );
	$actionid = (int) $actionid;
	$receiving_userid = (int) $receiving_userid;

	$where = array( 'apmeta_userid' => $current_userid, 'apmeta_actionid' => $actionid, 'apmeta_type' => $type, 'apmeta_value' => $receiving_userid );

	// get exteing vote if exists.
	$vote = ap_get_meta( $where );

	// If vote already exists by user then update count of vote.
	if ( $vote ) {
		$count = $vote['apmeta_param'] + $count;
		$row = ap_update_meta( array( 'apmeta_param' => $count ), $where );
		ap_clear_vote_count_cache( $actionid, $current_userid, $receiving_userid );
		return $row;
	}

	$row = ap_add_meta( $current_userid, $type, $actionid, $receiving_userid, $count );

	if ( $row !== false ) {
		ap_clear_vote_count_cache( $actionid, $current_userid, $receiving_userid );
		do_action( 'ap_vote_casted', $current_userid, $type, $actionid, $receiving_userid );
	}

	return $row;
}

/**
 * Add vote for post and also update post meta.
 * @param  integer $current_userid    User ID of user casting the vote.
 * @param  string  $type              Type of vote, "vote_up" or "vote_down".
 * @param  integer $actionid          Post ID.
 * @param  integer $receiving_userid  User ID of user receiving the vote.
 * @return array|false
 * @since  2.5
 */
function ap_add_post_vote( $current_userid, $type, $actionid, $receiving_userid, $count = 1 ) {
	$row = ap_add_vote( $current_userid, $type, $actionid, $receiving_userid, $count );

	if ( false !== $row ) {
		return ap_update_votes_count( $actionid );
	}

	return false;
}

/**
 * Remove vote meta from DB.
 */
function ap_remove_vote( $type, $current_userid, $actionid, $receiving_userid ) {
	// Snaitize varaiables.
	$current_userid = (int) $current_userid;
	$type = sanitize_title_for_query( $type );
	$actionid = (int) $actionid;
	$receiving_userid = (int) $receiving_userid;

	$row = ap_delete_meta( array( 'apmeta_type' => $type, 'apmeta_userid' => $current_userid, 'apmeta_actionid' => $actionid ) );

	if ( $row !== false ) {
		ap_clear_vote_count_cache( $type, $actionid, $current_userid, 'type', $receiving_userid );
		do_action( 'ap_vote_removed', $current_userid, $type, $actionid, $receiving_userid );
	}

	return $row;
}

/**
 * Retrieve vote count
 * If $actionid is passed then it count numbers of vote for a post
 * If $userid is passed then it count votes casted by a user.
 * If $receiving_userid is passed then it count numbers of votes received.
 *
 * @param bool|int $userid           User ID of user casting the vote
 * @param string   $type             Type of vote, "vote_up" or "vote_down"
 * @param boolean  $actionid         Post ID
 * @param integer  $receiving_userid User ID of user who received the vote
 *
 * @return int
 */
function ap_count_vote($userid = false, $type, $actionid = false, $receiving_userid = false) {

	if ( $actionid !== false ) {
		return ap_meta_total_count( $type, $actionid );
	} elseif ( $userid !== false ) {
		return ap_meta_total_count( $type, false, $userid );
	} elseif ( $receiving_userid !== false ) {
		return ap_meta_total_count( $type, false, false, false, $receiving_userid );
	}

	return 0;
}



/**
 * Count post vote count meta.
 * @param  integer $post_id Post id.
 * @return integer
 */
function ap_meta_post_votes($post_id) {
	$counts = ap_meta_total_count( array( 'vote_up', 'vote_down' ), $post_id, false, 'apmeta_type' );

	$counts_type = array( 'vote_up' => 0, 'vote_down' => 0 );
	if ( $counts ) {
		foreach ( $counts as $c ) {
			$counts_type[ $c->type ] = (int) $c->count;
		}
	}

	$vote = array();
	// Voted up count.
	$vote['votes_up'] = $counts_type['vote_up'];

	// Voted down count.
	$vote['votes_down'] = $counts_type['vote_down'];

	// Net vote.
	$vote['votes_net'] = $counts_type['vote_up'] - $counts_type['vote_down'];

	return $vote;
}

/**
 * Check if user voted on given post.
 *
 * @param int    $actionid
 * @param string $type
 * @param int    $userid
 *
 * @return bool
 *
 * @since 	2.0
 */
function ap_is_user_voted($actionid, $type, $userid = false) {

	if ( false === $userid ) {
		$userid = get_current_user_id();
	}

	if ( $type == 'vote' && is_user_logged_in() ) {
		global $wpdb;

		$query = $wpdb->prepare( 'SELECT apmeta_type as type, IFNULL(count(*), 0) as count FROM '.$wpdb->prefix.'ap_meta where (apmeta_type = "vote_up" OR apmeta_type = "vote_down") and apmeta_userid = %d and apmeta_actionid = %d GROUP BY apmeta_type', $userid, $actionid );

		$key = md5( $query );

		$user_done = wp_cache_get( $key, 'counts' );

		if ( $user_done === false ) {
			$user_done = $wpdb->get_row( $query );
			wp_cache_set( $key, $user_done, 'counts' );
		}

		return $user_done;
	} elseif ( is_user_logged_in() ) {
		$done = ap_meta_user_done( $type, $userid, $actionid );

		return $done > 0 ? true : false;
	}

	return false;
}

/**
 * Output or return voting button.
 *
 * @param 	int|object $post Post ID or object.
 * @param 	bool       $echo Echo or return vote button.
 * @return 	null|string
 * @since 0.1
 */
function ap_vote_btn( $post = null, $echo = true ) {
	$post = ap_get_post( $post );

	if ( 'answer' == $post->post_type && ap_opt( 'disable_voting_on_answer' ) ) {
		return;
	}

	if ( 'question' == $post->post_type && ap_opt( 'disable_voting_on_question' ) ) {
		return;
	}

	$nonce = wp_create_nonce( 'vote_'.$post->ID );
	$vote = ap_is_user_voted( $post->ID, 'vote' );

	$voted = $vote ? true : false;

	$type = $vote ? $vote->type : '';

	$html = '';
	$html .= '<div data-id="'.$post->ID.'" class="ap-vote net-vote" data-action="vote">';
	$html .= '<a class="'.ap_icon( 'vote_up' ).' ap-tip vote-up'.($voted ? ' voted' : '').($type == 'vote_down' ? ' disable' : '').'" data-query="ap_ajax_action=vote&type=up&post_id='.$post->ID.'&__nonce='.$nonce.'" href="#" title="'.__( 'Up vote this post', 'anspress-question-answer' ).'"></a>';

	$html .= '<span class="net-vote-count" data-view="ap-net-vote" itemprop="upvoteCount">'. ap_get_votes_net() .'</span>';

	if ( ('question' == $post->post_type && ! ap_opt( 'disable_down_vote_on_question' )) ||
		('answer' == $post->post_type && ! ap_opt( 'disable_down_vote_on_answer' )) ) {
		$html .= '<a data-tipposition="bottom center" class="'.ap_icon( 'vote_down' ).' ap-tip vote-down'.($voted ? ' voted' : '').($type == 'vote_up' ? ' disable' : '').'" data-query="ap_ajax_action=vote&type=down&post_id='.$post->ID.'&__nonce='.$nonce.'" href="#" title="'.__( 'Down vote this post', 'anspress-question-answer' ).'"></a>';
	}

	$html .= '</div>';

	if ( $echo ) {
		echo $html;
	} else {
		return $html;
	}
}

/**
 * post close vote count.
 *
 * @param bool|int $postid
 *
 * @return int
 */
function ap_post_close_vote($postid = false) {

	global $post;

	$postid = $postid ? $postid : $post->ID;

	return ap_meta_total_count( 'close', $postid );
}

// check if user voted for close
function ap_is_user_voted_closed($postid = false) {

	if ( is_user_logged_in() ) {
		global $post;
		$postid = $postid ? $postid : $post->ID;
		$userid = get_current_user_id();
		$done = ap_meta_user_done( 'close', $userid, $postid );

		return $done > 0 ? true : false;
	}

	return false;
}

// TODO: re-add closing system as an extension
function ap_close_vote_html() {

	if ( ! is_user_logged_in() ) {
		return;
	}

	global $post;
	$nonce = wp_create_nonce( 'close_'.$post->ID );
	$title = ( ! $post->voted_closed) ? (__( 'Vote for closing', 'anspress-question-answer' )) : (__( 'Undo your vote', 'anspress-question-answer' ));
	?>
		<a id="<?php echo 'close_'.$post->ID;
	?>" data-action="close-question" class="close-btn<?php echo ($post->voted_closed) ? ' closed' : '';
	?>" data-args="<?php echo $post->ID.'-'.$nonce;
	?>" href="#" title="<?php echo $title;
	?>">
			<?php _e( 'Close', 'anspress-question-answer' );
			echo($post->closed > 0 ? ' <span>('.$post->closed.')</span>' : '');
	?>
        </a>
	<?php

}

/**
 * Clear vote count cache.
 * @param  string|integer $actionid         Action id.
 * @param  string|integer $current_userid   Current user id.
 * @param  string|integer $receiving_userid Receiving user id.
 * @return string
 */
function ap_clear_vote_count_cache( $actionid = '', $current_userid = '', $receiving_userid = '' ) {

	$cache_key = 'vote_up_vote_down';

	if ( '' != $actionid ) {
		$cache_key .= '_'.$actionid;
	}

	if ( '' != $current_userid ) {
		$cache_key .= '_'. (int) $current_userid;
	}

	if ( '' != $receiving_userid ) {
		$cache_key .= '_'. (int) $receiving_userid;
	}

	$cache_key .= '_apmeta_type';
	wp_cache_delete( $cache_key , 'ap_meta_count' );
}
