<?php
/**
 * Email CRM — REST API (modules/email-crm/rest.php)
 *
 * Namespace gasf/v1, all routes under /crm. Every route re-checks approval
 * server-side on every request via gasf_crm_rest_guard(). Approval is never
 * inferred from anything the browser sends — an account revoked ten seconds ago
 * has to be locked out on its next call, not on its next page load.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function gasf_crm_rest_guard() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'gasf_crm_auth', 'Not signed in.', array( 'status' => 401 ) );
	}
	if ( ! gasf_crm_user_approved() ) {
		return new WP_Error( 'gasf_crm_pending', 'Your account is not approved.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Load a thread the current user is allowed to touch, or a WP_Error.
 *
 * Every route that takes a thread id goes through here. Approval gets you into
 * the building; the stream grant decides which rooms — and thread ids are
 * small sequential integers, so without this check a photos volunteer could
 * read, reply to, forward or ignore any general enquiry by typing a different
 * number into the URL.
 *
 * A thread outside the user's streams returns 404, not 403: a 403 would
 * confirm the thread exists, which is itself information about an inbox they
 * have no access to.
 */
function gasf_crm_rest_thread( $thread_id ) {
	$thread = gasf_crm_get_thread( (int) $thread_id );
	if ( ! $thread ) {
		return new WP_Error( 'gasf_crm_404', 'Thread not found.', array( 'status' => 404 ) );
	}
	if ( ! gasf_crm_user_can_stream( (string) $thread['stream'] ) ) {
		return new WP_Error( 'gasf_crm_404', 'Thread not found.', array( 'status' => 404 ) );
	}
	return $thread;
}

function gasf_crm_rest_require_case_workflow() {
	if ( ! function_exists( 'gasf_crm_case_workflow_enabled' ) || gasf_crm_case_workflow_enabled() ) {
		return true;
	}
	return new WP_Error( 'gasf_crm_case_workflow_disabled', 'Case workflow is disabled.', array( 'status' => 404 ) );
}

function gasf_crm_op_id_from_request( WP_REST_Request $req ) {
	$raw = (string) $req->get_param( 'op_id' );
	if ( '' === $raw ) {
		$raw = (string) $req->get_header( 'x-gasf-op-id' );
	}
	$raw = preg_replace( '~[^A-Za-z0-9._:-]~', '', $raw );
	return '' === $raw ? '' : substr( $raw, 0, 120 );
}

function gasf_crm_op_start( $scope, WP_REST_Request $req, $running_ttl = 900 ) {
	$op_id = gasf_crm_op_id_from_request( $req );
	if ( '' === $op_id ) {
		return array( 'enabled' => false, 'duplicate' => false, 'key' => '' );
	}

	$key   = 'gasf_crm_op_' . md5( (string) $scope . '|' . $op_id . '|' . get_current_user_id() );
	$state = get_transient( $key );
	if ( 'done' === $state ) {
		return array( 'enabled' => true, 'duplicate' => true, 'key' => $key );
	}
	if ( 'running' === $state ) {
		return new WP_Error( 'gasf_crm_inflight', 'That action is already in progress.', array( 'status' => 409 ) );
	}

	set_transient( $key, 'running', max( 30, (int) $running_ttl ) );
	return array( 'enabled' => true, 'duplicate' => false, 'key' => $key );
}

function gasf_crm_op_finish( array $token, $ok = true, $done_ttl = 3600 ) {
	if ( empty( $token['enabled'] ) || empty( $token['key'] ) ) { return; }
	if ( $ok ) {
		set_transient( (string) $token['key'], 'done', max( 60, (int) $done_ttl ) );
		return;
	}
	delete_transient( (string) $token['key'] );
}

add_action( 'rest_api_init', function () {
	$guard = 'gasf_crm_rest_guard';

	register_rest_route( 'gasf/v1', '/crm/threads', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$status = $req->get_param( 'status' ) ? sanitize_key( $req->get_param( 'status' ) ) : 'open';

			// The stream filter is intersected with what this user may see, so a
			// hand-edited ?stream= widens nothing — the worst it can do is show
			// fewer threads than they are entitled to.
			$allowed = gasf_crm_user_streams();
			$asked   = $req->get_param( 'stream' ) ? sanitize_key( $req->get_param( 'stream' ) ) : '';
			$streams = ( $asked && in_array( $asked, $allowed, true ) ) ? array( $asked ) : $allowed;

			$threads = gasf_crm_list_threads( $status, $streams );

			return array_map( function ( $t ) {
				$holder = $t['locked_by'] ? get_userdata( (int) $t['locked_by'] ) : null;
				$case   = gasf_crm_case_by_thread( (int) $t['id'] );
				$owner  = ( $case && ! empty( $case['owner_user_id'] ) ) ? get_userdata( (int) $case['owner_user_id'] ) : null;
				$exc    = $case ? gasf_crm_case_exception_count( (int) $case['id'] ) : 0;
				$queue  = 'active';
				if ( $exc > 0 ) {
					$queue = 'exceptions';
				} elseif ( $case && 'new' === (string) $case['state'] && empty( $case['owner_user_id'] ) ) {
					$queue = 'unassigned';
				} elseif ( $case && in_array( (string) $case['state'], array( 'waiting_external', 'blocked', 'ready_to_publish' ), true ) ) {
					$queue = (string) $case['state'];
				}
				return array(
					'id'         => (int) $t['id'],
					'subject'    => (string) $t['subject'],
					'from'       => $t['last_from_name'] ? $t['last_from_name'] : $t['last_from_addr'],
					'from_addr'  => (string) $t['last_from_addr'],
					'status'     => (string) $t['status'],
					'stream'     => (string) $t['stream'],
					'last'       => (string) $t['last_message_at'],
					'locked_by'  => $holder ? gasf_crm_display_name( $holder->ID ) : null,
					'locked_mine' => (int) $t['locked_by'] === get_current_user_id(),
					'case_state' => $case ? (string) $case['state'] : 'new',
					'owner_user_id' => $case ? (int) $case['owner_user_id'] : 0,
					'owner_name' => $owner ? gasf_crm_display_name( (int) $owner->ID ) : '',
					'exception_count' => $exc,
					'queue' => $queue,
				);
			}, $threads );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$thread = gasf_crm_rest_thread( $id );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$stream = (string) $thread['stream'];

			// Opening a thread claims it — that IS the lock, so two volunteers
			// cannot start writing the same reply. Failing to claim is not an
			// error: they still get to read it, just not send.
			$mine = gasf_crm_claim_thread( $id, get_current_user_id() );

			$messages = array_map( function ( $m ) use ( $stream ) {
				return array(
					'id'          => (int) $m['id'],
					'direction'   => (string) $m['direction'],
					'from'        => $m['from_name'] ? $m['from_name'] : $m['from_addr'],
					'from_addr'   => (string) $m['from_addr'],
					'sent_at'     => (string) $m['sent_at'],
					// Sanitised again on the way OUT, not only at ingest: rows
					// written before the sanitiser existed, or altered in the
					// database by anything else, would otherwise reach innerHTML
					// on trust. Costs microseconds on a handful of messages.
					'body'        => gasf_crm_email_kses( (string) $m['body_html'] ),
					'attachments' => ! empty( $m['has_attachments'] )
						? gasf_crm_attachment_list( $m['graph_message_id'], $stream )
						: array(),
				);
			}, gasf_crm_thread_messages( $id ) );

			$thread = gasf_crm_get_thread( $id ); // re-read: the claim changed it
			$case   = gasf_crm_case_by_thread( $id );
			$tasks  = $case ? gasf_crm_case_tasks( (int) $case['id'] ) : array();
			$cevs   = $case ? gasf_crm_case_events( (int) $case['id'], 24 ) : array();
			$owner  = ( $case && ! empty( $case['owner_user_id'] ) ) ? get_userdata( (int) $case['owner_user_id'] ) : null;
			$holder = $thread['locked_by'] ? get_userdata( (int) $thread['locked_by'] ) : null;

			return array(
				'id'        => $id,
				'subject'   => (string) $thread['subject'],
				'status'    => (string) $thread['status'],
				'stream'    => $stream,
				'mailbox'   => gasf_crm_stream_mailbox( $stream ),
				// Photos kept from this submission, with whatever the sender has
				// since told us about them. Always present, empty on a thread
				// nobody has taken a photo from.
				'photos'    => function_exists( 'gasf_crm_photo_thread_block' ) ? gasf_crm_photo_thread_block( $id ) : array(),
				'can_reply' => (bool) $mine,
				'locked_by' => ( ! $mine && $holder ) ? gasf_crm_display_name( $holder->ID ) : null,
				'case'      => array(
					'state'         => $case ? (string) $case['state'] : 'new',
					'owner_user_id' => $case ? (int) $case['owner_user_id'] : 0,
					'owner_name'    => $owner ? gasf_crm_display_name( (int) $owner->ID ) : '',
					'exception_count' => $case ? gasf_crm_case_exception_count( (int) $case['id'] ) : 0,
					'tasks' => array_map( function ( $t ) {
						return array(
							'id' => (int) $t['id'],
							'type' => (string) $t['type'],
							'state' => (string) $t['state'],
							'reason_code' => (string) $t['reason_code'],
							'details_json' => (string) $t['details_json'],
							'created_at' => (string) $t['created_at'],
							'resolved_at' => (string) $t['resolved_at'],
						);
					}, $tasks ),
					'events' => array_map( function ( $e ) {
						return array(
							'actor' => (string) $e['actor'],
							'action' => (string) $e['action'],
							'detail' => (string) $e['detail_json'],
							'at' => (string) $e['created_at'],
						);
					}, $cevs ),
				),
				'messages'  => $messages,
				'events'    => array_map( function ( $e ) {
					return array(
						'actor'  => (string) $e['actor'],
						'action' => (string) $e['action'],
						'detail' => (string) $e['detail'],
						'at'     => (string) $e['created_at'],
					);
				}, gasf_crm_thread_events( $id ) ),
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/release', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$thread = gasf_crm_rest_thread( (int) $req['id'] );
			if ( is_wp_error( $thread ) ) { return $thread; }
			gasf_crm_release_thread( (int) $req['id'], get_current_user_id() );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/takeover', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$thread = gasf_crm_rest_thread( $id );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$op = gasf_crm_op_start( 'thread-takeover:' . $id, $req, 10 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) { return array( 'ok' => true, 'duplicate' => true ); }
			if ( ! gasf_crm_claim_thread( $id, get_current_user_id(), 15, true ) ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_takeover_failed', 'Thread takeover failed.', array( 'status' => 409 ) );
			}
			gasf_crm_log_event( $id, 'takeover', 'Thread was taken over by another approved admin' );
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/cases', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$wf = gasf_crm_rest_require_case_workflow();
			if ( is_wp_error( $wf ) ) { return $wf; }
			if ( function_exists( 'gasf_crm_case_release_stale_owners' ) ) {
				gasf_crm_case_release_stale_owners( defined( 'GASF_CRM_CASE_OWNER_TIMEOUT_MINUTES' ) ? (int) GASF_CRM_CASE_OWNER_TIMEOUT_MINUTES : 1440 );
			}
			$queue = sanitize_key( (string) $req->get_param( 'queue' ) );
			if ( '' === $queue ) { $queue = 'active'; }
			$allowed = array( 'unassigned', 'active', 'waiting_external', 'blocked', 'ready_to_publish', 'exceptions', 'all' );
			if ( ! in_array( $queue, $allowed, true ) ) {
				return new WP_Error( 'gasf_crm_badqueue', 'Unknown queue.', array( 'status' => 400 ) );
			}
			$streams = gasf_crm_user_streams();
			if ( empty( $streams ) ) {
				return array( 'queue' => $queue, 'cases' => array() );
			}
			$rows = gasf_crm_case_list( $queue, 200, $streams );
			return array( 'queue' => $queue, 'cases' => $rows );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/case-state', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$wf = gasf_crm_rest_require_case_workflow();
			if ( is_wp_error( $wf ) ) { return $wf; }
			$thread = gasf_crm_rest_thread( (int) $req['id'] );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$state = sanitize_key( (string) $req->get_param( 'state' ) );
			$allowed = array( 'active', 'waiting_external', 'blocked', 'ready_to_publish' );
			if ( ! in_array( $state, $allowed, true ) ) {
				return new WP_Error( 'gasf_crm_badstate', 'That case state cannot be set here.', array( 'status' => 400 ) );
			}
			$op = gasf_crm_op_start( 'case-state:' . (int) $thread['id'] . ':' . $state, $req, 10 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) { return array( 'ok' => true, 'duplicate' => true ); }
			$ok = gasf_crm_case_set_state_by_thread(
				(int) $thread['id'],
				$state,
				'case.state_set',
				array( 'via' => 'api' ),
				get_current_user_id()
			);
			if ( ! $ok ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_transition', 'Case transition is not allowed from its current state.', array( 'status' => 409 ) );
			}
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/case', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$wf = gasf_crm_rest_require_case_workflow();
			if ( is_wp_error( $wf ) ) { return $wf; }
			$thread = gasf_crm_rest_thread( (int) $req['id'] );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$case = gasf_crm_case_by_thread( (int) $thread['id'] );
			if ( ! $case ) {
				return new WP_Error( 'gasf_crm_nocase', 'No case exists for this thread yet.', array( 'status' => 404 ) );
			}
			return array(
				'case' => $case,
				'tasks' => gasf_crm_case_tasks( (int) $case['id'] ),
				'events' => gasf_crm_case_events( (int) $case['id'] ),
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/exceptions/resolve', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$wf = gasf_crm_rest_require_case_workflow();
			if ( is_wp_error( $wf ) ) { return $wf; }
			$thread = gasf_crm_rest_thread( (int) $req['id'] );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$task_id = (int) $req->get_param( 'task_id' );
			$case = gasf_crm_case_by_thread( (int) $thread['id'] );
			if ( ! $case ) {
				return new WP_Error( 'gasf_crm_nocase', 'No case exists for this thread yet.', array( 'status' => 404 ) );
			}
			$reason = sanitize_key( (string) $req->get_param( 'reason_code' ) );
			$op_scope = ( $task_id > 0 )
				? 'case-resolve-task:' . (int) $thread['id'] . ':' . $task_id
				: 'case-resolve-reason:' . (int) $thread['id'] . ':' . $reason;
			$op = gasf_crm_op_start( $op_scope, $req, 10 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array( 'ok' => true, 'duplicate' => true, 'resolved' => 0 );
			}

			if ( $task_id > 0 ) {
				$ok = gasf_crm_case_resolve_task( (int) $case['id'], $task_id );
				if ( ! $ok ) {
					gasf_crm_op_finish( $op, false );
					return new WP_Error( 'gasf_crm_notask', 'That exception task was not found.', array( 'status' => 404 ) );
				}
				gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
				return array( 'ok' => true, 'resolved' => 1 );
			}

			$done = gasf_crm_case_resolve_exceptions_by_thread( (int) $thread['id'], $reason );
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'ok' => true, 'resolved' => (int) $done );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/case-owner', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$wf = gasf_crm_rest_require_case_workflow();
			if ( is_wp_error( $wf ) ) { return $wf; }
			$thread = gasf_crm_rest_thread( (int) $req['id'] );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$mode = sanitize_key( (string) $req->get_param( 'mode' ) );
			if ( '' === $mode ) { $mode = 'claim'; }
			if ( ! in_array( $mode, array( 'claim', 'release', 'takeover' ), true ) ) {
				return new WP_Error( 'gasf_crm_badowner', 'Unknown ownership action.', array( 'status' => 400 ) );
			}
			$op = gasf_crm_op_start( 'case-owner:' . (int) $thread['id'] . ':' . $mode, $req, 10 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array( 'ok' => true, 'duplicate' => true );
			}

			$actor = get_current_user_id();
			$owner = ( 'release' === $mode ) ? 0 : $actor;
			$ok = gasf_crm_case_set_owner_by_thread( (int) $thread['id'], $owner, 'case-owner:' . $mode, $actor );
			if ( ! $ok ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_ownerfail', 'Could not update case ownership.', array( 'status' => 500 ) );
			}
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'ok' => true, 'owner_user_id' => $owner );
		},
	) );

	// Closing a thread by hand, three ways. Separate routes rather than one
	// taking a status parameter, so the audit log records the operator's actual
	// intent — "answered elsewhere" and "this is spam" are different claims
	// about the same thread and should not be collapsed into one entry.
	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/addressed', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$thread = gasf_crm_rest_thread( $id );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$op = gasf_crm_op_start( 'thread-addressed:' . $id, $req, 10 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) { return array( 'ok' => true, 'duplicate' => true ); }
			gasf_crm_set_status( $id, 'addressed' );
			gasf_crm_log_event( $id, 'addressed', 'Marked answered without sending a reply' );
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/ignore', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$thread = gasf_crm_rest_thread( $id );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$reason = trim( sanitize_text_field( (string) $req->get_param( 'reason' ) ) );

			// A reason is required, and validated here rather than only in the
			// browser: this is what the audit log will show months later, and
			// "ignored" with no stated cause is the entry nobody can act on.
			if ( '' === $reason ) {
				return new WP_Error( 'gasf_crm_noreason',
					'Choose a reason for ignoring this message.', array( 'status' => 400 ) );
			}
			if ( mb_strlen( $reason ) > 120 ) {
				$reason = mb_substr( $reason, 0, 120 ) . '…';
			}
			$op = gasf_crm_op_start( 'thread-ignore:' . $id . ':' . md5( $reason ), $req, 10 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) { return array( 'ok' => true, 'duplicate' => true ); }

			gasf_crm_set_status( $id, 'ignored' );
			gasf_crm_log_event( $id, 'ignored', $reason );
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/restore', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id     = (int) $req['id'];
			$thread = gasf_crm_rest_thread( $id );
			if ( is_wp_error( $thread ) ) { return $thread; }
			$op = gasf_crm_op_start( 'thread-restore:' . $id, $req, 10 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) { return array( 'ok' => true, 'duplicate' => true ); }
			gasf_crm_set_status( $id, 'new' );
			gasf_crm_log_event( $id, 'restored', 'Returned to the open queue' );
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'ok' => true );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/draft', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$id = (int) $req['id'];
			$op = gasf_crm_op_start( 'thread-draft:' . $id, $req, 20 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				$cached = get_transient( $op['key'] . ':draft' );
				if ( is_string( $cached ) && '' !== $cached ) {
					return array( 'draft' => $cached, 'duplicate' => true );
				}
			}

			// Rate limit per user: the draft endpoint is the only one that costs
			// money, and a stuck retry loop in a browser tab should not be able
			// to run up a bill.
			$k = 'gasf_crm_draft_' . get_current_user_id();
			$n = (int) get_transient( $k );
			if ( $n >= 20 ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_rate', 'Too many drafts in the last hour. Try again later.', array( 'status' => 429 ) );
			}
			set_transient( $k, $n + 1, HOUR_IN_SECONDS );

			$thread = gasf_crm_rest_thread( $id );
			if ( is_wp_error( $thread ) ) {
				gasf_crm_op_finish( $op, false );
				return $thread;
			}

			$text = gasf_crm_draft_reply( $id );
			if ( is_wp_error( $text ) ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_draft', $text->get_error_message(), array( 'status' => 400 ) );
			}
			set_transient( $op['key'] . ':draft', (string) $text, HOUR_IN_SECONDS );
			gasf_crm_op_finish( $op, true, HOUR_IN_SECONDS );
			return array( 'draft' => $text );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/reply', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => 'gasf_crm_rest_reply',
	) );

	register_rest_route( 'gasf/v1', '/crm/threads/(?P<id>\d+)/forward', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => 'gasf_crm_rest_forward',
	) );

	register_rest_route( 'gasf/v1', '/crm/sync', array(
		'methods'             => 'POST',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$op = gasf_crm_op_start( 'mail-sync', $req, 2 * MINUTE_IN_SECONDS );
			if ( is_wp_error( $op ) ) { return $op; }
			if ( ! empty( $op['duplicate'] ) ) {
				return array( 'throttled' => true, 'new' => 0, 'reopened' => 0, 'duplicate' => true );
			}
			// Shared cooldown across all users, not per-user. The expensive thing
			// is the round trip to Microsoft, and it costs the same whether one
			// person presses this ten times or ten people press it once. The sync
			// lock already stops overlapping runs; this stops a queue of
			// back-to-back runs each fetching the same nothing.
			if ( get_transient( 'gasf_crm_manual_sync' ) ) {
				gasf_crm_op_finish( $op, true );
				return array( 'throttled' => true, 'new' => 0, 'reopened' => 0 );
			}
			set_transient( 'gasf_crm_manual_sync', time(), MINUTE_IN_SECONDS );

			$r = gasf_crm_sync();

			// Report a genuine upstream failure rather than a silent "nothing
			// new" — otherwise a broken mailbox connection looks identical to a
			// quiet morning, which is the worst possible failure mode here.
			if ( ! empty( $r['errors'] ) ) {
				gasf_crm_op_finish( $op, false );
				return new WP_Error( 'gasf_crm_sync', implode( '; ', $r['errors'] ), array( 'status' => 502 ) );
			}

			gasf_crm_op_finish( $op, true );
			return array(
				'throttled' => false,
				'new'       => (int) $r['new'],
				'reopened'  => (int) $r['reopened'],
			);
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/contacts', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			// Scoped to what this volunteer may read. The forward box suggesting
			// an address only ever seen in a mailbox they have no access to
			// would leak who writes to it, one autocomplete at a time.
			$rows = gasf_crm_contacts(
				sanitize_text_field( (string) $req->get_param( 'q' ) ),
				200,
				gasf_crm_user_streams()
			);
			return array_map( function ( $c ) {
				return array(
					'email' => (string) $c['email'],
					'name'  => (string) $c['name'],
					'sent'  => (int) $c['sent_count'],
					'recv'  => (int) $c['recv_count'],
					'last'  => (string) $c['last_seen'],
				);
			}, $rows );
		},
	) );

	register_rest_route( 'gasf/v1', '/crm/attachment', array(
		'methods'             => 'GET',
		'permission_callback' => $guard,
		'callback'            => 'gasf_crm_rest_attachment',
	) );

	// Outbound attachments: upload one, or list the shared library.
	register_rest_route( 'gasf/v1', '/crm/attachments', array(
		array(
			'methods'             => 'POST',
			'permission_callback' => $guard,
			'callback'            => function ( WP_REST_Request $req ) {
				$files = $req->get_file_params();
				if ( empty( $files['file'] ) ) {
					return new WP_Error( 'gasf_crm_nofile', 'No file was received.', array( 'status' => 400 ) );
				}
				$row = gasf_crm_attach_store(
					$files['file'],
					(bool) $req->get_param( 'keep' ),
					(string) $req->get_param( 'label' )
				);
				if ( is_wp_error( $row ) ) {
					// 400 rather than 500: every rejection here is something the
					// person can act on — wrong type, too big, empty file.
					return new WP_Error( 'gasf_crm_upload', $row->get_error_message(), array( 'status' => 400 ) );
				}
				return gasf_crm_attach_public( $row );
			},
		),
		array(
			'methods'             => 'GET',
			'permission_callback' => $guard,
			'callback'            => function () {
				return array_values( array_filter( array_map( 'gasf_crm_attach_public', gasf_crm_attach_library() ) ) );
			},
		),
	) );

	register_rest_route( 'gasf/v1', '/crm/attachments/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $guard,
		'callback'            => function ( WP_REST_Request $req ) {
			$row = gasf_crm_attach_get( (int) $req['id'] );
			if ( ! $row ) {
				return new WP_Error( 'gasf_crm_404', 'That file no longer exists.', array( 'status' => 404 ) );
			}
			// One-off uploads belong to their uploader. Ids are guessable
			// sequential integers, so without this any approved account could
			// delete another volunteer's pending upload out from under them.
			if ( ! gasf_crm_attach_can_use( $row, get_current_user_id() ) ) {
				return new WP_Error( 'gasf_crm_forbidden',
					'That file belongs to somebody else.', array( 'status' => 403 ) );
			}
			// A library document is shared property. Anyone may drop their own
			// upload, but removing one somebody else put there is an admin act.
			if ( ! empty( $row['in_library'] )
				&& ! current_user_can( 'manage_options' )
				&& (int) $row['uploaded_by'] !== get_current_user_id() ) {
				return new WP_Error( 'gasf_crm_forbidden',
					'Only an administrator can remove a shared document that somebody else added.',
					array( 'status' => 403 ) );
			}
			gasf_crm_attach_delete( (int) $req['id'] );
			return array( 'ok' => true );
		},
	) );
} );

/**
 * Forward the newest message in a thread to somebody else, and close it.
 *
 * Forwarding counts as answered. Handing something to the treasurer or the hall
 * booking person IS dealing with it — they reply directly, and that reply never
 * comes back through this mailbox, so waiting for one would leave the thread
 * sitting in Open forever. If it turns out we do still owe a reply, the thread
 * can be put back in Open from the Answered list.
 */
function gasf_crm_rest_forward( WP_REST_Request $req ) {
	global $wpdb;

	$thread_id = (int) $req['id'];
	$user_id   = get_current_user_id();

	$to = array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', (string) $req->get_param( 'to' ) ) ) );
	if ( ! $to ) {
		return new WP_Error( 'gasf_crm_norecip', 'Enter at least one address to forward to.', array( 'status' => 400 ) );
	}
	foreach ( $to as $addr ) {
		if ( ! is_email( $addr ) ) {
			return new WP_Error( 'gasf_crm_bademail', 'That does not look like an email address: ' . $addr, array( 'status' => 400 ) );
		}
	}

	/*
	 * Volume limit, counted in RECIPIENTS rather than requests.
	 *
	 * Replies are naturally bounded — they need the thread claim and go to the
	 * thread — but forward takes any addresses a volunteer types, which makes it
	 * the one place a compromised account could pump mail out of the club's
	 * mailbox until the domain's sending reputation is gone. Counting requests
	 * would leave one forward to fifty addresses inside the limit; counting
	 * recipients is counting the thing that actually goes out.
	 *
	 * Twenty an hour is roomy for the real use — a thread forwarded to a
	 * handful of board members — and useless for a spammer. Same transient
	 * shape as the draft endpoint's cap, so there is one pattern to know.
	 */
	$rk = 'gasf_crm_fwd_' . $user_id;
	$rn = (int) get_transient( $rk );
	if ( $rn >= 20 || ( $rn + count( $to ) ) > 20 ) {
		gasf_crm_log( sprintf( 'CRM forward: rate limit hit by user %d (%d sent this hour, %d more requested)',
			$user_id, $rn, count( $to ) ) );
		return new WP_Error( 'gasf_crm_rate',
			'That is more forwarding than this account can do in one hour (20 recipients). If this is club business at volume, do it from the mailbox itself.',
			array( 'status' => 429 ) );
	}
	set_transient( $rk, $rn + count( $to ), HOUR_IN_SECONDS );

	$thread = gasf_crm_rest_thread( $thread_id );
	if ( is_wp_error( $thread ) ) { return $thread; }
	$stream = (string) $thread['stream'];

	// Forward the newest message, whichever direction it came from — that is the
	// one carrying whatever prompted the forward.
	$target = $wpdb->get_row( $wpdb->prepare(
		'SELECT graph_message_id, has_attachments FROM ' . gasf_crm_table( 'messages' ) . '
		  WHERE thread_id = %d AND graph_message_id NOT LIKE %s
		  ORDER BY sent_at DESC, id DESC LIMIT 1',
		$thread_id, 'local-%'
	), ARRAY_A );

	// Placeholders are excluded above: they are rows the CRM wrote for its own
	// replies and carry synthetic ids Graph has never heard of.
	if ( ! $target ) {
		return new WP_Error( 'gasf_crm_nomsg',
			'Nothing in this conversation can be forwarded yet. If you just sent a reply, wait for the next sync.',
			array( 'status' => 400 ) );
	}

	$cfg     = gasf_crm_cfg();
	$name    = gasf_crm_display_name( $user_id );
	$note    = trim( (string) $req->get_param( 'comment' ) );
	$comment = ( '' !== $note ? wpautop( esc_html( $note ) ) : '' )
		. '<p>--<br>Forwarded by ' . esc_html( $name ) . '<br>' . esc_html( $cfg['signature_org'] ) . '</p>';

	$op = gasf_crm_op_start( 'thread-forward:' . $thread_id, $req, 20 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $op ) ) { return $op; }
	if ( ! empty( $op['duplicate'] ) ) {
		return array( 'ok' => true, 'duplicate' => true, 'to' => $to );
	}

	$sent = gasf_crm_graph_forward( $target['graph_message_id'], $to, $comment, $stream );
	if ( is_wp_error( $sent ) ) {
		gasf_crm_op_finish( $op, false );
		gasf_crm_log( 'CRM forward failed (thread ' . $thread_id . '): ' . $sent->get_error_message() );
		gasf_crm_case_open_exception_by_thread( $thread_id, 'forward_send_failed', $sent->get_error_message(), array( 'to' => $to ) );
		return new WP_Error( 'gasf_crm_send', $sent->get_error_message(), array( 'status' => 502 ) );
	}

	foreach ( $to as $addr ) {
		gasf_crm_touch_contact( $addr, '', 'out', (string) $thread['subject'], (string) $thread['stream'] );
	}

	// Record our own copy, as the reply path does. Three things depend on it:
	// the thread timeline showing that this was passed on; the next sync finding
	// this forward in Sent Items and adopting the placeholder instead of logging
	// it as "answered from Outlook"; and gasf_crm_settle_thread, which decides
	// status from the newest message and would otherwise see the inbound message
	// on top and reopen the thread we just closed.
	//
	// sent_by_user_id stays 0 deliberately: that flag is what feeds the AI
	// corpus, and "Forwarded to treasurer@…" is not a reply worth learning from.
	// The audit log records who did it.
	gasf_crm_insert_message( array(
		'thread_id'        => $thread_id,
		'graph_message_id' => 'local-fwd-' . $thread_id . '-' . time() . '-' . $user_id,
		'direction'        => 'out',
		'from_name'        => $name,
		'from_addr'        => gasf_crm_stream_mailbox( $stream ),
		'to_addrs'         => wp_json_encode( $to ),
		'sent_at'          => current_time( 'mysql', true ),
		'body_preview'     => 'Forwarded to ' . implode( ', ', $to ) . ( '' !== $note ? ' — ' . $note : '' ),
		'body_html'        => '<p><em>Forwarded to ' . esc_html( implode( ', ', $to ) ) . '</em></p>'
			. ( '' !== $note ? wpautop( esc_html( $note ) ) : '' ),
		// Graph /forward carries the original's attachments with it, so the
		// history row reflects what the forwarded message actually had.
		'has_attachments'  => ! empty( $target['has_attachments'] ),
		'sent_by_user_id'  => 0,
	) );

	gasf_crm_log_event( $thread_id, 'forwarded', 'Forwarded to ' . implode( ', ', $to ) . ' — closed as answered' );
	gasf_crm_set_status( $thread_id, 'addressed' );
	gasf_crm_case_resolve_exceptions_by_thread( $thread_id, 'forward_send_failed' );
	gasf_crm_log( 'CRM: thread ' . $thread_id . ' forwarded to ' . implode( ', ', $to ) . ' by user ' . $user_id );
	gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );

	return array( 'ok' => true, 'to' => $to );
}

/**
 * Send a reply, then mark the thread addressed.
 *
 * Replies to the most recent INBOUND message: replying to our own last outbound
 * would thread the message under the wrong parent, and some clients would show
 * the club talking to itself.
 */
function gasf_crm_rest_reply( WP_REST_Request $req ) {
	global $wpdb;

	$thread_id = (int) $req['id'];
	$user_id   = get_current_user_id();

	// The body is HTML now, from the contenteditable composer. Only approved
	// users can reach here, but it is still sanitised on arrival: an account can
	// be compromised, and this markup is both stored and re-rendered to every
	// other volunteer who opens the thread. Links are restricted to protocols
	// that cannot execute anything — the editor checks that too, but a
	// client-side check is a convenience, not a control.
	$clean = gasf_crm_email_kses( (string) $req->get_param( 'body' ), 'compose' );

	// Emptiness is judged on the text, not the markup: a composer left untouched
	// can still hand back "<p><br></p>", which is not a reply.
	if ( '' === trim( wp_strip_all_tags( $clean ) ) ) {
		return new WP_Error( 'gasf_crm_empty', 'The reply is empty.', array( 'status' => 400 ) );
	}

	$thread = gasf_crm_rest_thread( $thread_id );
	if ( is_wp_error( $thread ) ) { return $thread; }
	$stream = (string) $thread['stream'];

	// Re-check the lock at send time. The claim happened when the thread was
	// opened, possibly an hour ago — by now it may have expired and been taken.
	if ( ! gasf_crm_claim_thread( $thread_id, $user_id ) ) {
		$holder = get_userdata( (int) $thread['locked_by'] );
		return new WP_Error( 'gasf_crm_locked',
			( $holder ? gasf_crm_display_name( $holder->ID ) : 'Someone else' ) . ' is replying to this thread.',
			array( 'status' => 409 )
		);
	}

	$target = $wpdb->get_row( $wpdb->prepare(
		'SELECT graph_message_id FROM ' . gasf_crm_table( 'messages' ) . "
		  WHERE thread_id = %d AND direction = 'in'
		  ORDER BY sent_at DESC LIMIT 1", $thread_id
	), ARRAY_A );

	if ( ! $target ) {
		return new WP_Error( 'gasf_crm_noinbound', 'No inbound message to reply to.', array( 'status' => 400 ) );
	}

	$cfg  = gasf_crm_cfg();
	$user = wp_get_current_user();
	$name = gasf_crm_display_name( $user_id );

	$html = $clean
		. '<p>--<br>' . esc_html( $name ) . '<br>' . esc_html( $cfg['signature_org'] ) . '</p>';

	// Attachments are referenced by id, never uploaded as part of the send: the
	// file already sits on the server from the moment it was picked, so a slow
	// or dropped connection at send time cannot lose it.
	//
	// Every id is permission-checked, not merely resolved — ids are guessable
	// sequential integers. An id that fails the check behaves exactly like one
	// that no longer exists, so the response does not reveal whether a guessed
	// id was real.
	$requested   = array_values( array_filter( array_map( 'intval', (array) $req->get_param( 'attachments' ) ) ) );
	$attachments = array();
	$names       = array();
	foreach ( $requested as $aid ) {
		$row = gasf_crm_attach_get( $aid );
		if ( ! $row || ! gasf_crm_attach_can_use( $row, $user_id ) ) { continue; }
		$a = gasf_crm_attach_for_graph( $aid );
		if ( $a ) {
			$attachments[] = $a;
			$names[]       = $a['name'];
		}
	}

	// A file that vanished (or was never yours) between picking and sending is
	// worth stopping for. Silently sending a reply that says "the form is
	// attached" without the form is worse than making somebody press the
	// button again.
	if ( count( $requested ) !== count( $attachments ) ) {
		return new WP_Error( 'gasf_crm_attachlost',
			'One of the attachments could no longer be found. Attach it again and resend.',
			array( 'status' => 409 ) );
	}

	if ( $names ) {
		$html .= '<p><em>Attached: ' . esc_html( implode( ', ', $names ) ) . '</em></p>';
	}

	$op = gasf_crm_op_start( 'thread-reply:' . $thread_id, $req, 20 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $op ) ) { return $op; }
	if ( ! empty( $op['duplicate'] ) ) {
		return array( 'ok' => true, 'duplicate' => true );
	}

	// Sent from the thread's OWN mailbox — somebody who wrote to photos@ gets a
	// reply from photos@, not from info@. This is the single strongest argument
	// for real mailboxes over aliases, and it is one argument wide.
	$sent = $attachments
		? gasf_crm_graph_reply_with_attachments( $target['graph_message_id'], $html, $attachments, $stream )
		: gasf_crm_graph_reply( $target['graph_message_id'], $html, $stream );

	if ( is_wp_error( $sent ) ) {
		gasf_crm_op_finish( $op, false );
		gasf_crm_log( 'CRM reply failed (thread ' . $thread_id . '): ' . $sent->get_error_message() );
		gasf_crm_case_open_exception_by_thread( $thread_id, 'reply_send_failed', $sent->get_error_message(), array(
			'to' => (string) $thread['last_from_addr'],
		) );
		return new WP_Error( 'gasf_crm_send', $sent->get_error_message(), array( 'status' => 502 ) );
	}

	// Record our own copy immediately rather than waiting for the next sync to
	// find it in Sent Items, so the thread reads correctly the moment it is sent.
	// The Graph id is unknown here; a synthetic one keeps the UNIQUE index happy
	// and cannot collide with a real Graph id.
	gasf_crm_insert_message( array(
		'thread_id'        => $thread_id,
		'graph_message_id' => 'local-' . $thread_id . '-' . time() . '-' . $user_id,
		'direction'        => 'out',
		'from_name'        => $name,
		'from_addr'        => gasf_crm_stream_mailbox( $stream ),
		'to_addrs'         => wp_json_encode( array( $thread['last_from_addr'] ) ),
		'sent_at'          => current_time( 'mysql', true ),
		'body_preview'     => mb_substr( trim( wp_strip_all_tags( $clean ) ), 0, 500 ),
		'body_html'        => $html,
		// From the files actually sent — a hardcoded false here meant a reply
		// that carried the membership form showed no paperclip in History.
		'has_attachments'  => ! empty( $attachments ),
		'sent_by_user_id'  => $user_id,
	) );

	gasf_crm_touch_contact( $thread['last_from_addr'], $thread['last_from_name'], 'out', (string) $thread['subject'], (string) $thread['stream'] );
	gasf_crm_set_status( $thread_id, 'addressed' );
	gasf_crm_case_resolve_exceptions_by_thread( $thread_id, 'reply_send_failed' );
	gasf_crm_log_event( $thread_id, 'replied', 'Replied to ' . $thread['last_from_addr'] );
	gasf_crm_log( 'CRM: thread ' . $thread_id . ' answered by user ' . $user_id );
	gasf_crm_op_finish( $op, true, 4 * HOUR_IN_SECONDS );

	return array( 'ok' => true );
}

/** Attachment metadata for a message, best-effort — never fatal to a thread view. */
function gasf_crm_attachment_list( $graph_message_id, $stream = 'general' ) {
	$list = gasf_crm_graph_attachments( $graph_message_id, $stream );
	if ( is_wp_error( $list ) ) { return array(); }

	$out = array();
	foreach ( $list as $a ) {
		// Cloud links and attached emails carry no bytes. Marking them here lets
		// the chip say so up front, rather than sending someone to an error page
		// to find out.
		$kind = (string) ( $a['@odata.type'] ?? '' );
		$type = strtolower( (string) ( $a['contentType'] ?? '' ) );
		$out[] = array(
			'id'   => (string) ( $a['id'] ?? '' ),
			'msg'  => (string) $graph_message_id,
			'name' => (string) ( $a['name'] ?? 'attachment' ),
			'size' => (int) ( $a['size'] ?? 0 ),
			'kind' => false !== strpos( $kind, 'referenceAttachment' ) ? 'link'
				: ( false !== strpos( $kind, 'itemAttachment' ) ? 'email' : 'file' ),
			// Only a real fileAttachment with an image type can be sideloaded.
			// A cloud link has no bytes and an itemAttachment is an .eml, and
			// offering "Add to library" on either would be a button that only
			// ever produces an error.
			'image' => ( false === strpos( $kind, 'referenceAttachment' )
				&& false === strpos( $kind, 'itemAttachment' )
				&& 0 === strpos( $type, 'image/' ) ),
			// _wpnonce is what makes this link WORK at all. It renders as a plain
			// <a href>, and a bare navigation carries no X-WP-Nonce header — and
			// WordPress REST cookie auth without a nonce demotes the request to
			// anonymous, so every paperclip click was answered with a 401. The
			// query-param form is REST cookie-auth's sanctioned equivalent. The
			// nonce is minted fresh each time the thread is opened and is
			// useless without the session cookie it is bound to.
			// No mailbox or stream in the URL on purpose: the download route looks
			// that up from our own record of the message. A stream parameter here
			// would read as authoritative to the next person and invite them to
			// start trusting it, which is exactly the hole the lookup closes.
			'url'  => add_query_arg( array(
				'msg'      => rawurlencode( $graph_message_id ),
				'att'      => rawurlencode( (string) ( $a['id'] ?? '' ) ),
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			), rest_url( 'gasf/v1/crm/attachment' ) ),
		);
	}
	return $out;
}

/**
 * Stream an attachment through the server.
 *
 * Always Content-Disposition: attachment, never inline. These are files from
 * strangers; rendering one in the browser on our own origin would let an HTML
 * or SVG attachment run script against a signed-in volunteer's session.
 */
function gasf_crm_rest_attachment( WP_REST_Request $req ) {
	$msg = (string) $req->get_param( 'msg' );
	$att = (string) $req->get_param( 'att' );
	if ( '' === $msg || '' === $att ) {
		gasf_crm_attachment_problem( 'That download link is incomplete. Go back and open the message again.' );
	}

	// Which mailbox to fetch from is decided by OUR record of the message, never
	// by anything in the URL — otherwise a photos volunteer could point this at
	// the general mailbox and pull down attachments from an inbox they cannot
	// even list.
	//
	// Every mailbox holding a message with this id, not the first one the engine
	// happens to return. A Graph id is mailbox-scoped, so the same id can exist
	// in two streams — and picking arbitrarily meant a volunteer could be refused
	// a message they hold, or served from a mailbox they do not, depending on row
	// order. The caller's own grants decide which candidate is used, so the
	// authorisation still comes from our records rather than from the URL.
	global $wpdb;
	$streams = $wpdb->get_col( $wpdb->prepare(
		'SELECT DISTINCT stream FROM ' . gasf_crm_table( 'messages' ) . ' WHERE graph_message_id = %s',
		$msg
	) );

	$stream = '';
	foreach ( (array) $streams as $s ) {
		if ( gasf_crm_user_can_stream( (string) $s ) ) { $stream = (string) $s; break; }
	}
	if ( '' === $stream ) {
		gasf_crm_attachment_problem( 'That attachment is not available to your account.' );
	}

	// Metadata first, without the bytes: name and kind decide what happens next,
	// and pulling a 30 MB photo into memory to read its filename is exactly the
	// failure this path is built to avoid.
	$meta = gasf_crm_graph_attachment_meta( $msg, $att, $stream );
	if ( is_wp_error( $meta ) ) {
		gasf_crm_attachment_problem( $meta->get_error_message(), $msg, $stream );
	}

	$kind = (string) ( $meta['@odata.type'] ?? '' );
	if ( false !== strpos( $kind, 'referenceAttachment' ) ) {
		gasf_crm_attachment_problem( sprintf(
			'"%s" is a cloud link (OneDrive or SharePoint), not a file — there is nothing here to download. The sender shared it rather than attaching it.',
			(string) ( $meta['name'] ?? 'That attachment' )
		), $msg, $stream );
	}
	if ( false !== strpos( $kind, 'itemAttachment' ) ) {
		gasf_crm_attachment_problem( sprintf(
			'"%s" is another email attached to this one, rather than a file.',
			(string) ( $meta['name'] ?? 'That attachment' )
		), $msg, $stream );
	}

	$name = sanitize_file_name( (string) ( $meta['name'] ?? 'attachment' ) );
	$tmp  = wp_tempnam( 'gasf-crm-att' );
	if ( ! $tmp ) {
		gasf_crm_attachment_problem( 'The server could not make room to fetch that file.', $msg, $stream );
	}

	$ok = gasf_crm_graph_attachment_stream( $msg, $att, $tmp, $stream );
	if ( is_wp_error( $ok ) ) {
		@unlink( $tmp );
		gasf_crm_attachment_problem( $ok->get_error_message(), $msg, $stream );
	}

	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="' . ( '' !== $name ? $name : 'attachment' ) . '"' );
	header( 'Content-Length: ' . filesize( $tmp ) );
	header( 'X-Content-Type-Options: nosniff' );

	// Drop any buffered output before writing binary. WordPress or a plugin may
	// hold an ob_start() from earlier in the request, and a stray notice landing
	// in front of the bytes corrupts the file the volunteer receives — with no
	// error anywhere, just a download that will not open.
	while ( ob_get_level() > 0 ) { ob_end_clean(); }

	readfile( $tmp ); // streams in chunks; never holds the file in memory
	@unlink( $tmp );
	exit;
}

/**
 * Human-readable dead end for a download that cannot be served. Never returns.
 *
 * Offers the Outlook deep link ONLY to someone who could actually use it.
 * webLink opens the message in the shared mailbox's own OWA, so an
 * administrator gets the message and a Google-signed-in volunteer gets a
 * Microsoft sign-in wall — showing it to everyone would be sending most of
 * your volunteers to a locked door.
 */
function gasf_crm_attachment_problem( $message, $graph_message_id = '', $stream = '' ) {
	$outlook = '';
	if ( $graph_message_id && current_user_can( 'manage_options' ) ) {
		$outlook = gasf_crm_graph_message_weblink( $graph_message_id, $stream );
	}

	nocache_headers();
	status_header( 404 );
	header( 'Content-Type: text/html; charset=utf-8' );
	printf(
		'<!DOCTYPE html><html><head><meta charset="utf-8"><title>Attachment unavailable</title>'
		. '<style>body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;'
		. 'max-width:34em;margin:14vh auto;padding:0 20px;color:#1d2327}'
		. 'h1{font-size:19px;margin:0 0 10px}p{color:#50575e}'
		. '.btns{margin-top:18px;display:flex;gap:10px;flex-wrap:wrap}'
		. 'a{display:inline-block;padding:9px 16px;background:#2271b1;color:#fff;'
		. 'border-radius:4px;text-decoration:none}a.sec{background:#f6f7f7;color:#2271b1;border:1px solid #dcdcde}'
		. '</style></head><body>'
		. '<h1>That attachment cannot be downloaded</h1><p>%s</p><div class="btns">'
		. '<a href="%s">Back to the inbox</a>%s</div></body></html>',
		esc_html( $message ),
		esc_url( home_url( '/email/' ) ),
		$outlook
			? '<a class="sec" href="' . esc_url( $outlook ) . '" target="_blank" rel="noopener">Open the message in Outlook</a>'
			: ''
	);
	exit;
}
