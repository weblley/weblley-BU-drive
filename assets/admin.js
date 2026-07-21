/**
 * weblley BU drive 管理画面
 * - バックアップはバックグラウンド実行＋2秒ごとの進捗ポーリング（ブラウザを閉じてもOK）
 * - 復元は実行中オーバーレイ＋離脱警告
 * - アップロードは5MBずつの分割送信でサーバーのサイズ上限を回避
 */
(function () {
	'use strict';

	if ( typeof wbdData === 'undefined' ) {
		return;
	}

	var NOTE_STAY    = '⚠ このページを閉じたり、他のページへ移動したりしないでください。<br>移動すると処理が失敗します。';
	var NOTE_SAFE    = '処理はサーバー側で実行されるため、このページを閉じても続行されます。<br>完了するとこの画面に結果が表示されます。';
	var NOTE_MIGRATE = '別サイトからの移行モードで復元中です。処理はサーバー側で実行されるため、このページを閉じても続行されます。<br>完了後は<strong>移行元サイトのユーザー名・パスワード</strong>でログインし直してください。';

	var overlay = null;
	var msgEl   = null;
	var noteEl  = null;

	function warnBeforeUnload( e ) {
		e.preventDefault();
		e.returnValue = '';
	}

	function showOverlay( message, options ) {
		options = options || {};
		if ( ! overlay ) {
			overlay = document.createElement( 'div' );
			overlay.className = 'wbd-overlay';
			overlay.innerHTML =
				'<div class="wbd-overlay-box">' +
				'<span class="spinner is-active"></span>' +
				'<p class="wbd-overlay-msg"></p>' +
				'<p class="wbd-overlay-note"></p>' +
				'</div>';
			document.body.appendChild( overlay );
			msgEl  = overlay.querySelector( '.wbd-overlay-msg' );
			noteEl = overlay.querySelector( '.wbd-overlay-note' );
		}
		msgEl.textContent = message;
		noteEl.innerHTML  = options.note || NOTE_STAY;
		overlay.style.display = 'flex';
		if ( options.warnOnLeave ) {
			window.addEventListener( 'beforeunload', warnBeforeUnload );
		}
	}

	function updateOverlay( message ) {
		if ( msgEl ) {
			msgEl.textContent = message;
		}
	}

	function formatRemain( seconds ) {
		if ( seconds >= 3600 ) {
			return Math.round( seconds / 3600 * 10 ) / 10 + '時間';
		}
		if ( seconds >= 60 ) {
			return Math.round( seconds / 60 ) + '分';
		}
		return Math.max( 5, seconds ) + '秒';
	}

	// 結果を通知付きでページ再読み込み
	function finish( ok, message ) {
		window.removeEventListener( 'beforeunload', warnBeforeUnload );
		window.location.href = wbdData.pageUrl +
			'&wbd_notice=' + encodeURIComponent( message ) +
			'&wbd_status=' + ( ok ? 'success' : 'error' );
	}

	function finishJson( json ) {
		var ok      = !! ( json && json.success );
		var message = ( json && json.data && json.data.message ) ? json.data.message : '通信エラーが発生しました。';
		finish( ok, message );
	}

	// ---- バックグラウンド実行＋進捗ポーリング（バックアップ／復元 共通） ----
	var pollFailures = 0;
	var pollKey      = null;

	// 移行復元の完了画面: セッションが切り替わっているため、リダイレクトせず
	// その場に結果とログインボタンを表示する
	function showMigrationResult( ok, message ) {
		window.removeEventListener( 'beforeunload', warnBeforeUnload );
		if ( overlay ) {
			var sp = overlay.querySelector( '.spinner' );
			if ( sp ) {
				sp.classList.remove( 'is-active' );
			}
		}
		updateOverlay( ( ok ? '✅ ' : '⚠ ' ) + message );
		if ( noteEl ) {
			noteEl.innerHTML = ok
				? '移行によりログインセッションが切り替わりました。<br><strong>移行元サイトのユーザー名・パスワード</strong>でログインし直してください。'
				: '復元前の緊急DBダンプが保管ディレクトリに残っています。ログインし直して実行ログを確認してください。';
		}
		var box = overlay && overlay.querySelector( '.wbd-overlay-box' );
		if ( box && ! box.querySelector( '.wbd-login-link' ) ) {
			var link = document.createElement( 'a' );
			link.className   = 'button button-primary wbd-login-link';
			link.href        = wbdData.loginUrl;
			link.textContent = 'ログイン画面へ';
			box.appendChild( link );
		}
	}

	function pollJob() {
		var fd = new FormData();
		fd.append( 'action', 'wbd_status' );
		fd.append( '_wpnonce', wbdData.statusNonce );
		if ( pollKey ) {
			fd.append( 'poll_key', pollKey );
		}

		fetch( wbdData.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				pollFailures = 0;
				var job = ( json && json.data ) || {};
				if ( 'running' === job.status ) {
					var text = job.phase || '処理を実行中です…';
					// 全体%（復元はサーバー計算のpercent、バックアップは件数から算出）
					var pct = null;
					if ( 'number' === typeof job.percent ) {
						pct = job.percent;
					} else if ( job.total > 0 && job.done > 0 ) {
						pct = Math.floor( ( job.done / job.total ) * 100 );
					}
					if ( null !== pct ) {
						text += '（' + pct + '%';
						// 経過時間と進捗率から残り時間を概算
						if ( 'number' === typeof job.elapsed && pct >= 3 && pct < 100 && job.elapsed >= 10 ) {
							var remain = Math.round( job.elapsed * ( 100 - pct ) / pct );
							text += '・残り約' + formatRemain( remain );
						}
						text += '）';
					}
					updateOverlay( text );
					window.setTimeout( pollJob, 2000 );
				} else if ( 'done' === job.status ) {
					if ( job.migration ) {
						showMigrationResult( true, job.message || '復元が完了しました。' );
					} else {
						finish( true, job.message || '処理が完了しました。' );
					}
				} else if ( 'error' === job.status ) {
					if ( job.migration ) {
						showMigrationResult( false, job.message || '復元に失敗しました。' );
					} else {
						finish( false, job.message || '処理に失敗しました。' );
					}
				} else {
					window.setTimeout( pollJob, 2000 );
				}
			} )
			.catch( function () {
				// 一時的な通信エラー。諦めずに再試行し続ける（間隔は緩める）
				pollFailures++;
				if ( pollFailures >= 5 ) {
					updateOverlay( '通信が不安定なため進捗を確認しにくくなっていますが、処理はサーバー側で継続しています。そのままお待ちください…' );
				}
				window.setTimeout( pollJob, pollFailures >= 5 ? 8000 : 3000 );
			} );
	}

	function startBackgroundJob( form, startingMessage ) {
		showOverlay( startingMessage, { note: NOTE_SAFE } );
		fetch( wbdData.ajaxUrl, { method: 'POST', body: new FormData( form ), credentials: 'same-origin' } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( json && json.success ) {
					if ( json.data ) {
						pollKey = json.data.pollKey || null;
						if ( json.data.migration && noteEl ) {
							noteEl.innerHTML = NOTE_MIGRATE;
						}
					}
					window.setTimeout( pollJob, 1500 );
				} else {
					finishJson( json );
				}
			} )
			.catch( function () { finish( false, '処理の開始に失敗しました。' ); } );
	}

	var backupForm = document.getElementById( 'wbd-backup-form' );
	if ( backupForm ) {
		backupForm.addEventListener( 'submit', function ( e ) {
			if ( e.defaultPrevented ) {
				return;
			}
			e.preventDefault();
			startBackgroundJob( backupForm, 'バックアップを開始しています…' );
		} );
	}

	// ---- 復元（confirmはインライン属性側で表示済み） ----
	document.querySelectorAll( '.wbd-restore-form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( e.defaultPrevented ) {
				return;
			}
			e.preventDefault();
			startBackgroundJob( form, '復元を開始しています…' );
		} );
	} );

	// ページ表示時に実行中のジョブがあれば進捗表示を再開（ブラウザを閉じて戻ってきた場合）
	if ( wbdData.job && 'running' === wbdData.job.status ) {
		showOverlay(
			wbdData.job.phase || '処理を実行中です…',
			{ note: wbdData.job.migration ? NOTE_MIGRATE : NOTE_SAFE }
		);
		window.setTimeout( pollJob, 1000 );
	}

	// ---- 分割アップロード ----
	var uploadForm = document.getElementById( 'wbd-upload-form' );
	if ( uploadForm ) {
		uploadForm.addEventListener( 'submit', function ( e ) {
			if ( e.defaultPrevented ) {
				return;
			}
			e.preventDefault();

			var input = uploadForm.querySelector( 'input[type="file"]' );
			var file  = input && input.files && input.files[0];
			if ( ! file ) {
				return;
			}
			if ( ! /\.zip$/i.test( file.name ) ) {
				window.alert( 'ZIPファイルのみアップロードできます。' );
				return;
			}

			var chunkSize = wbdData.chunkSize;
			var total     = Math.max( 1, Math.ceil( file.size / chunkSize ) );
			var nonce     = uploadForm.querySelector( 'input[name="_wpnonce"]' ).value;
			var index     = 0;

			showOverlay( 'アップロード中… 0%', { warnOnLeave: true } );

			function sendNext() {
				var fd = new FormData();
				fd.append( 'action', 'wbd_upload_chunk' );
				fd.append( '_wpnonce', nonce );
				fd.append( 'name', file.name );
				fd.append( 'index', index );
				fd.append( 'total', total );
				fd.append( 'chunk', file.slice( index * chunkSize, ( index + 1 ) * chunkSize ), 'chunk' );

				return fetch( wbdData.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( res ) { return res.json(); } )
					.then( function ( json ) {
						if ( ! json.success ) {
							finishJson( json );
							return;
						}
						index++;
						if ( index < total ) {
							updateOverlay( 'アップロード中… ' + Math.floor( ( index / total ) * 100 ) + '%' );
							return sendNext();
						}
						finishJson( json );
					} );
			}

			sendNext().catch( function () {
				finish( false, 'アップロード中に通信が中断されました。' );
			} );
		} );
	}
})();
