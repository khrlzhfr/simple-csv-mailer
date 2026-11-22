<?php
defined( 'ABSPATH' ) || exit;

/**
 * Tools → Mailer: send personalised emails to a CSV list in batches.
 */
final class SCM_Mailer {

	const CAP        = 'edit_others_posts'; // Editors and above.
	const SLUG       = 'scm-mailer';
	const MAX_BATCH  = 20;                  // Emails per page load, keeps under PHP time limits.
	const MAX_UPLOAD = 5242880;             // 5 MB.
	const MAX_ATTACH = 10485760;            // 10 MB per email.

	/** Screen hook of Tools → Mailer. */
	private static $hook = '';

	/** Result of this request's sending batch, for the progress screen. */
	private static $batch = null;

	/** Lists that can be sent, cached for this request. */
	private static $pending = null;

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'migrate_storage' ], 5 );
		add_action( 'admin_init', [ __CLASS__, 'early_actions' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
	}

	public static function activate() {
		self::migrate_storage();
		self::ensure_dir();
	}

	/* ---------- Storage ---------- */

	/** wp-content/uploads/mailer (uploads/sites/{ID}/mailer on multisite). */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/mailer';
	}

	/** Versions before 1.2.0 kept files in wp-content/mailer. Moves them once. */
	public static function migrate_storage() {
		if ( get_option( 'scm_storage_version' ) ) {
			return;
		}
		$old = untrailingslashit( WP_CONTENT_DIR ) . '/mailer';
		$new = self::dir();
		if ( is_dir( $old ) && ! file_exists( $new ) && wp_mkdir_p( dirname( $new ) ) ) {
			rename( $old, $new ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- one-time move of this plugin's own folder.
		}
		update_option( 'scm_storage_version', 2, false );
	}

	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( "$dir/index.php" ) ) {
			file_put_contents( "$dir/index.php", "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( "$dir/.htaccess" ) ) {
			file_put_contents( "$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/** Full path for a bare filename in the mailer folder, or false. */
	public static function path( $name ) {
		$name = basename( (string) $name );
		if ( ! preg_match( '/^[A-Za-z0-9._-]+\.(csv|txt)$/', $name ) ) {
			return false;
		}
		$path = self::dir() . '/' . $name;
		return is_file( $path ) ? $path : false;
	}

	public static function files( $completed = false ) {
		$out = [];
		foreach ( glob( self::dir() . '/*.csv' ) ?: [] as $f ) {
			$n       = basename( $f );
			$is_done = 0 === strpos( $n, 'completed_' );
			if ( $is_done === $completed ) {
				$out[ $n ] = filemtime( $f );
			}
		}
		arsort( $out );
		return array_keys( $out );
	}

	public static function domain() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return preg_replace( '/^www\./i', '', $host );
	}

	public static function now() {
		return wp_date( 'Y-m-d H:i:s' );
	}

	public static function stamp() {
		return wp_date( 'Ymd_His' );
	}

	/* ---------- CSV ---------- */

	public static function key( $header ) {
		$k = strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header ) ) );
		$k = preg_replace( '/[^a-z0-9]+/', '_', $k );
		return trim( $k, '_' );
	}

	/** Reads a CSV. Returns [headers, keys, rows, email, status] or null. */
	public static function read( $path ) {
		$headers = [];
		$rows    = [];
		$h       = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $h ) {
			return null;
		}
		flock( $h, LOCK_SH );
		if ( fread( $h, 3 ) !== "\xEF\xBB\xBF" ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			rewind( $h );
		}
		while ( ( $r = fgetcsv( $h, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( ! $headers ) {
				$headers = $r;
				continue;
			}
			if ( [ null ] === $r ) {
				continue; // Blank line.
			}
			$rows[] = array_pad( $r, count( $headers ), '' );
		}
		flock( $h, LOCK_UN );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $headers ) {
			return null;
		}

		$keys  = array_map( [ __CLASS__, 'key' ], $headers );
		$email = array_search( 'email', $keys, true );
		if ( false === $email ) {
			foreach ( $keys as $i => $k ) {
				if ( false !== strpos( $k, 'email' ) && 'email_status' !== $k ) {
					$email = $i;
					break;
				}
			}
		}
		$status = array_search( 'email_status', $keys, true );
		return compact( 'headers', 'keys', 'rows', 'email', 'status' );
	}

	/** Atomic write: temp file then rename. Keeps a UTF-8 BOM so Excel opens it cleanly. */
	public static function write( $path, $headers, $rows ) {
		$tmp = $path . '.tmp';
		$h   = fopen( $tmp, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $h, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $h, $headers, ',', '"', '\\' );
		foreach ( $rows as $r ) {
			fputcsv( $h, $r, ',', '"', '\\' );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return rename( $tmp, $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	public static function recipients( $csv ) {
		if ( ! $csv || false === $csv['email'] ) {
			return 0;
		}
		$n = 0;
		foreach ( $csv['rows'] as $r ) {
			if ( '' !== trim( $r[ $csv['email'] ] ) ) {
				$n++;
			}
		}
		return $n;
	}

	public static function merge( $text, $csv, $row, $html ) {
		foreach ( $csv['keys'] as $i => $k ) {
			if ( '' === $k || 'email_status' === $k ) {
				continue;
			}
			$v    = trim( (string) ( $row[ $i ] ?? '' ) );
			$text = str_replace( '%' . $k . '%', $html ? esc_html( $v ) : $v, $text );
		}
		return $text;
	}

	/* ---------- Request helpers ---------- */

	/** A posted text field, unslashed and sanitised. Nonces are checked before any handler reads input. */
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in early_actions().
	}

	/** A posted multi-line field, unslashed and sanitised. */
	private static function post_lines( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in early_actions().
	}

	private static function get( $key ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, or nonce checked by the caller.
	}

	/* ---------- Mail ---------- */

	public static function parse_list( $s, &$bad ) {
		$ok = [];
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $s ) ) ) as $e ) {
			if ( is_email( $e ) ) {
				$ok[] = $e;
			} else {
				$bad[] = $e;
			}
		}
		return $ok;
	}

	public static function job_from_post() {
		$bad = [];
		$job = [
			'file'        => basename( self::post( 'file' ) ),
			'from_name'   => self::post( 'from_name' ),
			'alias'       => strtolower( self::post( 'alias' ) ),
			'reply_to'    => '',
			'cc'          => '',
			'bcc'         => '',
			'subject'     => self::post( 'subject' ),
			'body'        => isset( $_POST['scm_body'] ) ? wp_kses_post( wp_unslash( $_POST['scm_body'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in early_actions().
			'attachments' => implode( "\n", array_filter( array_map( 'trim', preg_split( '/\R/', self::post_lines( 'attachments' ) ) ) ) ),
			'count'       => max( 1, min( self::MAX_BATCH, (int) self::post( 'rate_count' ) ) ),
			'seconds'     => max( 1, min( 3600, (int) self::post( 'rate_seconds' ) ) ),
		];
		$job['reply_to'] = implode( ', ', self::parse_list( self::post( 'reply_to' ), $bad ) );
		$job['cc']       = implode( ', ', self::parse_list( self::post( 'cc' ), $bad ) );
		$job['bcc']      = implode( ', ', self::parse_list( self::post( 'bcc' ), $bad ) );

		$errors = [];
		if ( ! preg_match( '/^[a-z0-9._+-]+$/', $job['alias'] ) ) {
			$errors[] = esc_html__( 'The From address can only use letters, numbers, dots, dashes, underscores and plus signs.', 'simple-csv-mailer' );
		}
		if ( $bad ) {
			/* translators: %s: list of invalid email addresses */
			$errors[] = esc_html( sprintf( __( 'Invalid Reply-To, Cc or Bcc address: %s', 'simple-csv-mailer' ), implode( ', ', $bad ) ) );
		}
		if ( '' === $job['subject'] ) {
			$errors[] = esc_html__( 'Subject is empty.', 'simple-csv-mailer' );
		}
		if ( '' === trim( wp_strip_all_tags( $job['body'] ) ) ) {
			$errors[] = esc_html__( 'Body is empty.', 'simple-csv-mailer' );
		}
		foreach ( array_filter( explode( "\n", $job['attachments'] ) ) as $u ) {
			if ( ! preg_match( '#^https?://\S+$#i', $u ) && ! preg_match( '#^%[^%\s]+%$#', $u ) ) {
				/* translators: %s: attachment line */
				$errors[] = esc_html( sprintf( __( 'Attachment is not a URL: %s', 'simple-csv-mailer' ), $u ) );
			}
		}
		return [ $job, $errors ];
	}

	public static function from( $job ) {
		$addr = $job['alias'] . '@' . self::domain();
		return '' !== $job['from_name'] ? sprintf( '%s <%s>', $job['from_name'], $addr ) : $addr;
	}

	public static function send_one( $job, $csv, $row, $to, $test = false ) {
		$subject = str_replace( [ "\r", "\n" ], ' ', self::merge( $job['subject'], $csv, $row, false ) );
		if ( $test ) {
			$subject = '[TEST] ' . $subject;
		}
		$body = wpautop( self::merge( $job['body'], $csv, $row, true ) );

		$headers = [ 'Content-Type: text/html; charset=UTF-8', 'From: ' . self::from( $job ) ];
		if ( $job['reply_to'] ) {
			$headers[] = 'Reply-To: ' . $job['reply_to'];
		}
		if ( $job['cc'] ) {
			$headers[] = 'Cc: ' . $job['cc'];
		}
		if ( $job['bcc'] ) {
			$headers[] = 'Bcc: ' . $job['bcc'];
		}

		$files = self::attachments( $job, $csv, $row );
		if ( false === $files ) {
			return false;
		}
		return wp_mail( $to, $subject, $body, $headers, $files );
	}

	/* ---------- Attachments ---------- */

	public static function cache_dir() {
		$d = self::dir() . '/cache';
		if ( ! is_dir( $d ) ) {
			wp_mkdir_p( $d );
			self::ensure_dir();
		}
		return $d;
	}

	/** Local file for a URL, downloading it if needed. False if unreachable. */
	public static function fetch( $url ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return false;
		}
		$uploads = wp_get_upload_dir();
		$site    = untrailingslashit( home_url() );
		$base    = untrailingslashit( $uploads['baseurl'] );
		$local   = false;
		if ( 0 === strpos( $url, $base . '/' ) ) {
			$local = $uploads['basedir'] . '/' . rawurldecode( strtok( substr( $url, strlen( $base ) + 1 ), '?#' ) );
		} elseif ( 0 === strpos( $url, $site . '/' ) ) {
			$local = ABSPATH . rawurldecode( strtok( substr( $url, strlen( $site ) + 1 ), '?#' ) );
		}
		if ( $local ) {
			$real = realpath( $local );
			if ( $real && is_file( $real ) && 0 === strpos( $real, realpath( ABSPATH ) ) ) {
				return [ 'path' => $real, 'name' => basename( $real ) ];
			}
			// Fall through: pretty URLs that are not files on disk get downloaded.
		}
		$name  = sanitize_file_name( rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) );
		$name  = $name ? $name : 'attachment';
		$cache = self::cache_dir() . '/' . md5( $url ) . '_' . $name;
		if ( is_file( $cache ) && filemtime( $cache ) > time() - DAY_IN_SECONDS ) {
			return [ 'path' => $cache, 'name' => $name ];
		}
		$res = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 30,
				'stream'              => true,
				'filename'            => $cache,
				'limit_response_size' => self::MAX_ATTACH,
			]
		);
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) || ! is_file( $cache ) || ! filesize( $cache ) ) {
			if ( is_file( $cache ) ) {
				wp_delete_file( $cache );
			}
			return false;
		}
		if ( preg_match( '/filename\*?=(?:UTF-8\'\')?"?([^";]+)/i', (string) wp_remote_retrieve_header( $res, 'content-disposition' ), $m ) ) {
			$header_name = sanitize_file_name( rawurldecode( trim( $m[1] ) ) );
			$name        = $header_name ? $header_name : $name;
		}
		return [ 'path' => $cache, 'name' => $name ];
	}

	/** Attachment list for one recipient, keyed by filename for wp_mail(). False if any file failed. */
	public static function attachments( $job, $csv, $row ) {
		$out   = [];
		$total = 0;
		foreach ( array_filter( explode( "\n", (string) ( $job['attachments'] ?? '' ) ) ) as $line ) {
			$url = trim( self::merge( $line, $csv, $row, false ) );
			if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
				continue; // An empty merge tag means no file for this person.
			}
			$f = self::fetch( $url );
			if ( ! $f ) {
				return false;
			}
			$total += filesize( $f['path'] );
			if ( $total > self::MAX_ATTACH ) {
				return false;
			}
			$name = $f['name'];
			$i    = 1;
			while ( isset( $out[ $name ] ) ) {
				$name = preg_replace( '/(\.[^.]+)?$/', '-' . ( ++$i ) . '$1', $f['name'], 1 );
			}
			$out[ $name ] = $f['path'];
		}
		return $out;
	}

	public static function clear_cache() {
		foreach ( glob( self::dir() . '/cache/*' ) ?: [] as $f ) {
			if ( is_file( $f ) && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
				wp_delete_file( $f );
			}
		}
	}

	/* ---------- Jobs ---------- */

	public static function job_key( $file ) {
		return 'scm_job_' . md5( $file );
	}

	public static function lock_key( $file ) {
		return 'scm_lock_' . md5( $file );
	}

	/** Sends one batch. Returns ['sent'=>n, 'remaining'=>n, 'failed'=>n, 'done'=>bool] or null. */
	public static function run_batch( $job ) {
		$path = self::path( $job['file'] );
		if ( ! $path ) {
			return null;
		}
		$csv = self::read( $path );
		if ( ! $csv || false === $csv['email'] ) {
			return null;
		}

		if ( false === $csv['status'] ) {
			$csv['headers'][] = 'email_status';
			$csv['keys'][]    = 'email_status';
			$csv['status']    = count( $csv['headers'] ) - 1;
			foreach ( $csv['rows'] as &$r ) {
				$r[] = '';
			}
			unset( $r );
		}
		$e = $csv['email'];
		$s = $csv['status'];

		$done_emails = [];
		foreach ( $csv['rows'] as $r ) {
			if ( 0 === strpos( (string) $r[ $s ], 'Sent' ) ) {
				$done_emails[ strtolower( trim( $r[ $e ] ) ) ] = true;
			}
		}

		$attempts = 0;
		foreach ( $csv['rows'] as &$r ) {
			if ( '' !== trim( (string) $r[ $s ] ) ) {
				continue;
			}
			if ( $attempts >= $job['count'] ) {
				break;
			}
			$email = trim( (string) $r[ $e ] );
			$lc    = strtolower( $email );
			if ( '' === $email || ! is_email( $email ) ) {
				$r[ $s ] = 'Invalid ' . self::now();
				continue;
			}
			if ( isset( $done_emails[ $lc ] ) ) {
				$r[ $s ] = 'Skipped ' . self::now();
				continue;
			}
			$ok      = self::send_one( $job, $csv, $r, $email );
			$r[ $s ] = ( $ok ? 'Sent ' : 'Failed ' ) . self::now();
			if ( $ok ) {
				$done_emails[ $lc ] = true;
			}
			$attempts++;
		}
		unset( $r );

		// Mark trailing invalid/duplicate rows now so the last batch can finish.
		$remaining = 0;
		$failed    = 0;
		foreach ( $csv['rows'] as &$r ) {
			$st = trim( (string) $r[ $s ] );
			if ( '' === $st ) {
				$email = trim( (string) $r[ $e ] );
				if ( '' === $email || ! is_email( $email ) ) {
					$r[ $s ] = 'Invalid ' . self::now();
					continue;
				}
				if ( isset( $done_emails[ strtolower( $email ) ] ) ) {
					$r[ $s ] = 'Skipped ' . self::now();
					continue;
				}
				$remaining++;
			} elseif ( 0 === strpos( $st, 'Failed' ) ) {
				$failed++;
			}
		}
		unset( $r );

		self::write( $path, $csv['headers'], $csv['rows'] );
		return [
			'sent'      => $attempts,
			'remaining' => $remaining,
			'failed'    => $failed,
			'done'      => 0 === $remaining,
		];
	}

	public static function complete( $job ) {
		self::clear_cache();
		$path = self::path( $job['file'] );
		if ( ! $path ) {
			return false;
		}
		$base = 'completed_' . self::stamp() . '_' . preg_replace( '/\.csv$/', '', $job['file'] );
		$txt  = implode(
			"\n",
			[
				'From: ' . self::from( $job ),
				'Reply-To: ' . ( $job['reply_to'] ? $job['reply_to'] : '-' ),
				'Cc: ' . ( $job['cc'] ? $job['cc'] : '-' ),
				'Bcc: ' . ( $job['bcc'] ? $job['bcc'] : '-' ),
				'Subject: ' . $job['subject'],
				'Completed: ' . self::now(),
				'',
				'Body:',
				$job['body'],
			]
		);
		file_put_contents( self::dir() . "/$base.txt", $txt ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		rename( $path, self::dir() . "/$base.csv" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		delete_option( self::job_key( $job['file'] ) );
		delete_transient( self::lock_key( $job['file'] ) );
		return "$base.csv";
	}

	/* ---------- Admin plumbing ---------- */

	public static function menu() {
		self::$hook = (string) add_management_page(
			__( 'Mailer', 'simple-csv-mailer' ),
			__( 'Mailer', 'simple-csv-mailer' ),
			self::CAP,
			self::SLUG,
			[ __CLASS__, 'page' ]
		);
	}

	public static function url( $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'tools.php' ) );
	}

	/** Stores a notice for the next page load. $html must already be escaped. */
	public static function notice( $html, $type = 'success', $alert = '' ) {
		set_transient( 'scm_notice_' . get_current_user_id(), [ $html, $type, $alert ], 60 );
	}

	private static function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/** Downloads, form posts and sending batches run here, before any admin output. */
	public static function early_actions() {
		if ( self::get( 'page' ) !== self::SLUG ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'simple-csv-mailer' ), 403 );
		}

		if ( '' !== self::get( 'download' ) ) {
			check_admin_referer( 'scm_download' );
			$path = self::path( self::get( 'download' ) );
			if ( ! $path ) {
				wp_die( esc_html__( 'File not found.', 'simple-csv-mailer' ) );
			}
			nocache_headers();
			header( 'Content-Type: ' . ( substr( $path, -4 ) === '.csv' ? 'text/csv' : 'text/plain' ) . '; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
			header( 'Content-Length: ' . filesize( $path ) );
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams one of this plugin's own lists as a download.
			exit;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' === $method && isset( $_POST['scm_action'] ) ) {
			check_admin_referer( 'scm_form' );
			self::ensure_dir();
			self::handle_post( sanitize_key( wp_unslash( $_POST['scm_action'] ) ) );
			return;
		}

		if ( '' !== self::get( 'sending' ) ) {
			check_admin_referer( 'scm_sending' );
			self::sending_batch( basename( self::get( 'sending' ) ) );
		}
	}

	public static function enqueue( $hook ) {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'scm-admin', plugins_url( 'assets/admin.css', SCM_FILE ), [], SCM_VERSION );

		if ( null !== self::$batch ) {
			if ( empty( self::$batch['error'] ) ) {
				wp_enqueue_script( 'scm-sending', plugins_url( 'assets/sending.js', SCM_FILE ), [], SCM_VERSION, true );
				wp_add_inline_script( 'scm-sending', 'window.scmSending = ' . wp_json_encode( [ 'seconds' => (int) self::$batch['seconds'] ] ) . ';', 'before' );
			}
			return;
		}

		$n    = get_transient( 'scm_notice_' . get_current_user_id() );
		$data = [
			'lists'   => self::pending(),
			'from'    => '@' . self::domain(),
			'alert'   => is_array( $n ) && ! empty( $n[2] ) ? (string) $n[2] : '',
			'i18n'    => [
				'noList'  => __( 'Upload or select a CSV to see its merge tags.', 'simple-csv-mailer' ),
				/* translators: 1: number of emails, 2: duration */
				'eta'     => __( '%1$s email(s) will be sent in approximately %2$s.', 'simple-csv-mailer' ),
				/* translators: 1: number of recipients, 2: From address */
				'confirm' => __( 'Send to %1$s recipient(s) from %2$s?', 'simple-csv-mailer' ),
				'hour'    => __( 'hour', 'simple-csv-mailer' ),
				'hours'   => __( 'hours', 'simple-csv-mailer' ),
				'minute'  => __( 'minute', 'simple-csv-mailer' ),
				'minutes' => __( 'minutes', 'simple-csv-mailer' ),
				'second'  => __( 'second', 'simple-csv-mailer' ),
				'seconds' => __( 'seconds', 'simple-csv-mailer' ),
			],
		];
		wp_enqueue_script( 'scm-admin', plugins_url( 'assets/admin.js', SCM_FILE ), [], SCM_VERSION, true );
		wp_add_inline_script( 'scm-admin', 'window.scmData = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/** Lists that can be sent, with counts and merge tags. */
	private static function pending() {
		if ( null !== self::$pending ) {
			return self::$pending;
		}
		self::$pending = [];
		foreach ( self::files( false ) as $n ) {
			$csv = self::read( self::dir() . "/$n" );
			if ( ! $csv || false === $csv['email'] ) {
				continue;
			}
			$sent = 0;
			if ( false !== $csv['status'] ) {
				foreach ( $csv['rows'] as $r ) {
					if ( '' !== trim( $r[ $csv['status'] ] ) ) {
						$sent++;
					}
				}
			}
			self::$pending[ $n ] = [
				'count'  => self::recipients( $csv ),
				'tags'   => array_values(
					array_filter(
						$csv['keys'],
						static function ( $k ) {
							return '' !== $k && 'email_status' !== $k;
						}
					)
				),
				'done'   => $sent,
				'resume' => (bool) get_option( self::job_key( $n ) ),
			];
		}
		return self::$pending;
	}

	public static function handle_post( $action ) {
		$uid = get_current_user_id();

		if ( 'upload' === $action ) {
			$f = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in early_actions(); each part is validated below.
			if ( ! $f || ! isset( $f['error'], $f['tmp_name'], $f['name'], $f['size'] ) || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
				self::notice( esc_html__( 'Upload failed.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}
			$orig = sanitize_file_name( wp_unslash( $f['name'] ) );
			if ( (int) $f['size'] > self::MAX_UPLOAD ) {
				self::notice( esc_html__( 'The file is larger than 5 MB.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}
			if ( 'csv' !== strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) ) ) {
				self::notice( esc_html__( 'Only .csv files can be uploaded.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}
			$csv = self::read( $f['tmp_name'] );
			if ( ! $csv || false === $csv['email'] ) {
				self::notice( esc_html__( 'The CSV needs a header row with an "email" column.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}

			$base = preg_replace( '/[^A-Za-z0-9_-]+/', '_', pathinfo( $orig, PATHINFO_FILENAME ) );
			$base = $base ? $base : 'list';
			$base = preg_replace( '/^completed_/', '', $base );
			$name = sprintf( '%s_%s_%s.csv', $base, self::stamp(), strtolower( wp_generate_password( 6, false ) ) );
			// Save the rows already parsed above rather than moving the raw upload, so the stored list is a clean CSV.
			if ( ! self::write( self::dir() . "/$name", $csv['headers'], $csv['rows'] ) ) {
				self::notice( esc_html__( 'Could not save the file.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}
			update_user_meta( $uid, 'scm_last_file', $name );
			/* translators: 1: file name, 2: number of recipients */
			self::notice( esc_html( sprintf( __( 'Uploaded %1$s (%2$d recipients).', 'simple-csv-mailer' ), $name, self::recipients( $csv ) ) ) );
			self::redirect( self::url() );
		}

		if ( 'delete' === $action ) {
			$name = basename( self::post( 'file' ) );
			$path = self::path( $name );
			if ( $path ) {
				wp_delete_file( $path );
				$txt = self::path( preg_replace( '/\.csv$/', '.txt', $name ) );
				if ( $txt ) {
					wp_delete_file( $txt );
				}
				delete_option( self::job_key( $name ) );
				/* translators: %s: file name */
				self::notice( esc_html( sprintf( __( 'Deleted %s.', 'simple-csv-mailer' ), $name ) ) );
			}
			self::redirect( self::url() );
		}

		if ( 'retry' === $action ) {
			$name = basename( self::post( 'file' ) );
			$path = self::path( $name );
			$csv  = $path ? self::read( $path ) : null;
			if ( ! $csv || false === $csv['status'] ) {
				self::redirect( self::url() );
			}
			$rows = [];
			foreach ( $csv['rows'] as $r ) {
				if ( 0 === strpos( (string) $r[ $csv['status'] ], 'Failed' ) ) {
					$r[ $csv['status'] ] = '';
					$rows[]              = $r;
				}
			}
			if ( ! $rows ) {
				self::notice( esc_html__( 'No failed rows to retry.', 'simple-csv-mailer' ), 'warning' );
				self::redirect( self::url() );
			}
			$base = preg_replace( [ '/^completed_\d{8}_\d{6}_/', '/\.csv$/' ], '', $name );
			$new  = sprintf( 'retry_%s_%s.csv', $base, self::stamp() );
			self::write( self::dir() . "/$new", $csv['headers'], $rows );
			update_user_meta( $uid, 'scm_last_file', $new );
			/* translators: 1: file name, 2: number of rows */
			self::notice( esc_html( sprintf( __( 'Created %1$s with %2$d failed rows. Select it under To and send again.', 'simple-csv-mailer' ), $new, count( $rows ) ) ) );
			self::redirect( self::url() );
		}

		// Test and send share the compose form.
		list( $job, $errors ) = self::job_from_post();
		update_user_meta( $uid, 'scm_draft', $job );
		update_user_meta( $uid, 'scm_last_file', $job['file'] );
		$path = self::path( $job['file'] );
		$csv  = $path ? self::read( $path ) : null;
		if ( ! $csv || false === $csv['email'] ) {
			$errors[] = esc_html__( 'Select a CSV under To.', 'simple-csv-mailer' );
		}
		if ( $errors ) {
			self::notice( implode( '<br>', $errors ), 'error' );
			self::redirect( self::url() );
		}

		if ( 'test' === $action ) {
			$to = sanitize_email( self::post( 'test_to' ) );
			if ( ! is_email( $to ) ) {
				self::notice( esc_html__( 'Enter a valid test email address.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}
			$first = null;
			foreach ( $csv['rows'] as $r ) {
				if ( is_email( trim( $r[ $csv['email'] ] ) ) ) {
					$first = $r;
					break;
				}
			}
			if ( ! $first ) {
				self::notice( esc_html__( 'No valid recipient in the CSV.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}
			$ok = self::send_one( $job, $csv, $first, $to, true );
			if ( $ok ) {
				/* translators: %s: email address */
				self::notice( esc_html( sprintf( __( "Test sent to %s using the first recipient's data.", 'simple-csv-mailer' ), $to ) ) );
			} else {
				self::notice( esc_html__( "The test was not sent. Check the site's mail setup and that every attachment URL can be reached.", 'simple-csv-mailer' ), 'error' );
			}
			self::redirect( self::url() );
		}

		if ( 'send' === $action ) {
			if ( get_transient( self::lock_key( $job['file'] ) ) ) {
				self::notice( esc_html__( 'This list is already being sent in another tab or by another user.', 'simple-csv-mailer' ), 'error' );
				self::redirect( self::url() );
			}
			$job['started'] = self::now();
			$job['user']    = $uid;
			update_option( self::job_key( $job['file'] ), $job, false );
			self::redirect( self::sending_url( $job['file'] ) );
		}

		self::redirect( self::url() );
	}

	private static function sending_url( $file ) {
		return self::url(
			[
				'sending'  => $file,
				'_wpnonce' => wp_create_nonce( 'scm_sending' ),
			]
		);
	}

	/** Runs one batch for the progress screen, or finishes the job and redirects. */
	private static function sending_batch( $file ) {
		$job = get_option( self::job_key( $file ) );
		if ( ! $job || ! self::path( $file ) ) {
			self::$batch = [ 'error' => __( 'No sending job for this file.', 'simple-csv-mailer' ) ];
			return;
		}

		// One tab at a time: the tab holding the lock keeps a matching cookie.
		$lock  = self::lock_key( $file );
		$token = get_transient( $lock );
		$mine  = isset( $_COOKIE['scm_tab'] ) ? sanitize_key( wp_unslash( $_COOKIE['scm_tab'] ) ) : '';
		if ( $token && $token !== $mine ) {
			self::$batch = [ 'error' => __( 'This list is already being sent in another tab. Close the other tab, wait a minute, then resume.', 'simple-csv-mailer' ) ];
			return;
		}
		if ( ! $token ) {
			$mine = strtolower( wp_generate_password( 12, false ) );
			setcookie( 'scm_tab', $mine, [ 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ] );
		}
		set_transient( $lock, $mine, $job['seconds'] + 60 );

		$res = self::run_batch( $job );
		if ( ! $res ) {
			delete_transient( $lock );
			self::$batch = [ 'error' => __( 'Could not read the CSV.', 'simple-csv-mailer' ) ];
			return;
		}

		if ( $res['done'] ) {
			$done = self::complete( $job );
			/* translators: %s: completed file name */
			$msg = sprintf( __( 'Sending complete. %s', 'simple-csv-mailer' ), $done );
			if ( $res['failed'] ) {
				/* translators: %d: number of failed rows */
				$msg .= ' ' . sprintf( __( '(%d failed: use "Retry failed" under Completed)', 'simple-csv-mailer' ), $res['failed'] );
			}
			self::notice( esc_html( $msg ), 'success', $msg );
			self::redirect( self::url() );
		}

		self::$batch = array_merge(
			$res,
			[
				'file'    => $file,
				'seconds' => (int) $job['seconds'],
				'eta'     => (int) ceil( $res['remaining'] / $job['count'] ) * $job['seconds'],
			]
		);
	}

	public static function duration( $s ) {
		$s     = max( 0, (int) $s );
		$h     = intdiv( $s, 3600 );
		$m     = intdiv( $s % 3600, 60 );
		$sec   = $s % 60;
		$parts = [];
		if ( $h ) {
			/* translators: %d: number of hours */
			$parts[] = sprintf( _n( '%d hour', '%d hours', $h, 'simple-csv-mailer' ), $h );
		}
		if ( $m ) {
			/* translators: %d: number of minutes */
			$parts[] = sprintf( _n( '%d minute', '%d minutes', $m, 'simple-csv-mailer' ), $m );
		}
		if ( $sec || ! $parts ) {
			/* translators: %d: number of seconds */
			$parts[] = sprintf( _n( '%d second', '%d seconds', $sec, 'simple-csv-mailer' ), $sec );
		}
		return implode( ' ', $parts );
	}

	/* ---------- Screens ---------- */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'simple-csv-mailer' ) );
		}
		self::ensure_dir();
		if ( null !== self::$batch ) {
			self::sending_page();
			return;
		}
		self::main_page();
	}

	private static function sending_page() {
		$b = self::$batch;
		echo '<div class="wrap"><h1>' . esc_html__( 'Mailer: sending', 'simple-csv-mailer' ) . '</h1>';
		if ( isset( $b['error'] ) ) {
			echo '<p>' . esc_html( $b['error'] ) . '</p><p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Mailer', 'simple-csv-mailer' ) . '</a></p></div>';
			return;
		}
		printf( '<p><strong>%s</strong></p>', esc_html( $b['file'] ) );
		/* translators: 1: emails sent in this round, 2: emails remaining, 3: time left */
		echo '<p>' . esc_html( sprintf( __( 'Sent %1$d in this round. %2$d remaining, about %3$s left.', 'simple-csv-mailer' ), $b['sent'], $b['remaining'], self::duration( $b['eta'] ) ) ) . '</p>';
		/* translators: %d: seconds between batches */
		echo '<p>' . esc_html( sprintf( _n( 'Keep this tab open. It reloads every %d second.', 'Keep this tab open. It reloads every %d seconds.', $b['seconds'], 'simple-csv-mailer' ), $b['seconds'] ) ) . '</p>';
		printf(
			'<p><a class="button" href="%s" onclick="return confirm(%s)">%s</a></p></div>',
			esc_url( self::url() ),
			esc_attr( wp_json_encode( __( 'Pause sending? You can resume from Mailer.', 'simple-csv-mailer' ) ) ),
			esc_html__( 'Pause', 'simple-csv-mailer' )
		);
	}

	private static function main_page() {
		$uid   = get_current_user_id();
		$saved = get_user_meta( $uid, 'scm_draft', true );
		$draft = wp_parse_args(
			is_array( $saved ) ? $saved : [],
			[
				'from_name'   => '',
				'alias'       => '',
				'reply_to'    => '',
				'cc'          => '',
				'bcc'         => '',
				'subject'     => '',
				'body'        => '',
				'attachments' => '',
				'count'       => 1,
				'seconds'     => 3,
			]
		);
		$last    = get_user_meta( $uid, 'scm_last_file', true );
		$pending = self::pending();
		$domain  = self::domain();

		$n = get_transient( 'scm_notice_' . $uid );
		if ( is_array( $n ) ) {
			delete_transient( 'scm_notice_' . $uid );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), wp_kses_post( $n[0] ) );
		}
		?>
		<div class="wrap scm">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Mailer', 'simple-csv-mailer' ); ?></h1>
			<a href="#" class="upload-view-toggle page-title-action" id="scm-upload-toggle" role="button" aria-expanded="false"><span class="upload"><?php esc_html_e( 'Upload CSV', 'simple-csv-mailer' ); ?></span><span class="browse"><?php esc_html_e( 'Close', 'simple-csv-mailer' ); ?></span></a>
			<hr class="wp-header-end">

			<div class="upload-plugin" id="scm-upload">
				<p class="install-help">
					<?php
					/* translators: %s: the word email in code formatting */
					echo wp_kses_post( sprintf( __( 'Upload a CSV with a header row and an %s column. Other columns become merge tags.', 'simple-csv-mailer' ), '<code>email</code>' ) );
					?>
				</p>
				<form method="post" enctype="multipart/form-data" class="wp-upload-form">
					<?php wp_nonce_field( 'scm_form' ); ?>
					<input type="hidden" name="scm_action" value="upload">
					<label class="screen-reader-text" for="scm-csv"><?php esc_html_e( 'CSV file', 'simple-csv-mailer' ); ?></label>
					<input type="file" name="csv" id="scm-csv" accept=".csv,text/csv" required>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Upload', 'simple-csv-mailer' ); ?>" disabled>
				</form>
			</div>

			<div class="notice notice-warning inline">
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: link to the wp_mail() documentation */
							__( "<strong>Use with care.</strong> Mailer sends through WordPress's built-in %s function, so every email goes out from this site's mail service and under its reputation. Only send to addresses from verified and trusted sources, such as people who have signed up or asked to hear from you. Never send to purchased or scraped lists.", 'simple-csv-mailer' ),
							'<a href="https://developer.wordpress.org/reference/functions/wp_mail/" target="_blank" rel="noopener">wp_mail()</a>'
						)
					);
					?>
				</p>
				<p><?php esc_html_e( 'To avoid bounces and abuse complaints: remove addresses that bounced last time before sending again, keep the list fresh so old addresses are not reused, say who you are and why the recipient is hearing from you, set a Reply-To that someone reads, and tell people how to stop receiving your emails. Send a test to yourself first, and keep the rate low. Too many bounces or complaints can get the site\'s sending address blocked.', 'simple-csv-mailer' ); ?></p>
			</div>

			<form method="post" id="scm-compose">
				<?php wp_nonce_field( 'scm_form' ); ?>
				<input type="hidden" name="scm_action" id="scm-action" value="test">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="scm-file"><?php esc_html_e( 'To', 'simple-csv-mailer' ); ?></label></th>
						<td>
							<?php if ( ! $pending ) : ?>
								<p><?php esc_html_e( 'No CSV uploaded yet.', 'simple-csv-mailer' ); ?></p>
							<?php else : ?>
								<select name="file" id="scm-file" required>
									<?php foreach ( $pending as $name => $info ) : ?>
										<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $name, $last ); ?>>
											<?php
											echo esc_html(
												$info['done']
													/* translators: 1: file name, 2: number of recipients, 3: number already processed */
													? sprintf( __( '%1$s (%2$d recipients, %3$d already processed)', 'simple-csv-mailer' ), $name, $info['count'], $info['done'] )
													/* translators: 1: file name, 2: number of recipients */
													: sprintf( __( '%1$s (%2$d recipients)', 'simple-csv-mailer' ), $name, $info['count'] )
											);
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									/* translators: %s: the column name email_status in code formatting */
									echo wp_kses_post( sprintf( __( 'Rows that already have an %s are not sent again, so a paused list continues where it stopped.', 'simple-csv-mailer' ), '<code>email_status</code>' ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-from-name"><?php esc_html_e( 'From name', 'simple-csv-mailer' ); ?></label></th>
						<td><input type="text" name="from_name" id="scm-from-name" class="regular-text" value="<?php echo esc_attr( $draft['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-alias"><?php esc_html_e( 'From address', 'simple-csv-mailer' ); ?></label></th>
						<td>
							<input type="text" name="alias" id="scm-alias" required pattern="[A-Za-z0-9._+\-]+" value="<?php echo esc_attr( $draft['alias'] ); ?>" placeholder="noreply"> @<?php echo esc_html( $domain ); ?>
							<p class="description"><?php esc_html_e( 'Must be allowed by your mail service (for example a verified sender in Amazon SES). Some SMTP plugins replace the From address with their own.', 'simple-csv-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-reply"><?php esc_html_e( 'Reply-To', 'simple-csv-mailer' ); ?></label></th>
						<td><input type="text" name="reply_to" id="scm-reply" class="regular-text" value="<?php echo esc_attr( $draft['reply_to'] ); ?>" placeholder="<?php echo esc_attr( 'user1@' . $domain . ', user2@' . $domain ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-cc"><?php esc_html_e( 'Cc', 'simple-csv-mailer' ); ?></label></th>
						<td><input type="text" name="cc" id="scm-cc" class="regular-text" value="<?php echo esc_attr( $draft['cc'] ); ?>" placeholder="<?php echo esc_attr( 'user3@' . $domain . ', user4@' . $domain ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-bcc"><?php esc_html_e( 'Bcc', 'simple-csv-mailer' ); ?></label></th>
						<td><input type="text" name="bcc" id="scm-bcc" class="regular-text" value="<?php echo esc_attr( $draft['bcc'] ); ?>" placeholder="<?php echo esc_attr( 'user5@' . $domain . ', user6@' . $domain ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Merge tags', 'simple-csv-mailer' ); ?></th>
						<td><p class="scm-tags" id="scm-tags"></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-subject"><?php esc_html_e( 'Subject', 'simple-csv-mailer' ); ?></label></th>
						<td><input type="text" name="subject" id="scm-subject" class="large-text" required value="<?php echo esc_attr( $draft['subject'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Body', 'simple-csv-mailer' ); ?></th>
						<td>
							<?php
							wp_editor(
								$draft['body'],
								'scm_body',
								[
									'textarea_name' => 'scm_body',
									'textarea_rows' => 16,
									'media_buttons' => false,
								]
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-attachments"><?php esc_html_e( 'Attachments', 'simple-csv-mailer' ); ?></label></th>
						<td>
							<textarea name="attachments" id="scm-attachments" class="large-text code" rows="3" placeholder="<?php echo esc_attr( content_url( 'uploads/2026/11/invitation.pdf' ) ); ?>"><?php echo esc_textarea( $draft['attachments'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One URL per line. Merge tags work here, so a column can hold a different file for each person. Up to 10 MB per email.', 'simple-csv-mailer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="scm-test"><?php esc_html_e( 'Test', 'simple-csv-mailer' ); ?></label></th>
						<td>
							<input type="email" name="test_to" id="scm-test" class="regular-text" placeholder="you@example.com">
							<button class="button" data-action="test"><?php esc_html_e( 'Send test', 'simple-csv-mailer' ); ?></button>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sending rate', 'simple-csv-mailer' ); ?></th>
						<td>
							<label>
								<input type="number" name="rate_count" id="scm-count" class="small-text" min="1" max="<?php echo (int) self::MAX_BATCH; ?>" value="<?php echo (int) $draft['count']; ?>">
								<?php esc_html_e( 'email(s) every', 'simple-csv-mailer' ); ?>
							</label>
							<label>
								<input type="number" name="rate_seconds" id="scm-seconds" class="small-text" min="1" max="3600" value="<?php echo (int) $draft['seconds']; ?>">
								<?php esc_html_e( 'second(s)', 'simple-csv-mailer' ); ?>
							</label>
							<p class="description" id="scm-eta"></p>
						</td>
					</tr>
				</table>
				<p class="submit"><button class="button button-primary" data-action="send"><?php esc_html_e( 'Send', 'simple-csv-mailer' ); ?></button></p>
			</form>

			<?php
			$resumable = array_filter(
				$pending,
				static function ( $i ) {
					return $i['resume'];
				}
			);
			if ( $resumable ) :
				?>
				<h2><?php esc_html_e( 'Paused', 'simple-csv-mailer' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'File', 'simple-csv-mailer' ); ?></th><th><?php esc_html_e( 'Progress', 'simple-csv-mailer' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $resumable as $name => $info ) : ?>
						<tr>
							<td><?php echo esc_html( $name ); ?></td>
							<td>
								<?php
								/* translators: 1: rows processed, 2: total recipients */
								echo esc_html( sprintf( __( '%1$d of %2$d processed', 'simple-csv-mailer' ), $info['done'], $info['count'] ) );
								?>
							</td>
							<td><a class="button" href="<?php echo esc_url( self::sending_url( $name ) ); ?>"><?php esc_html_e( 'Resume', 'simple-csv-mailer' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Completed', 'simple-csv-mailer' ); ?></h2>
			<?php
			$completed = self::files( true );
			if ( ! $completed ) :
				?>
				<p><?php esc_html_e( 'Nothing sent yet.', 'simple-csv-mailer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'File', 'simple-csv-mailer' ); ?></th><th><?php esc_html_e( 'Result', 'simple-csv-mailer' ); ?></th><th><?php esc_html_e( 'Download', 'simple-csv-mailer' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php
					foreach ( $completed as $name ) :
						$csv = self::read( self::dir() . "/$name" );
						$c   = [
							'Sent'    => 0,
							'Skipped' => 0,
							'Invalid' => 0,
							'Failed'  => 0,
						];
						if ( $csv && false !== $csv['status'] ) {
							foreach ( $csv['rows'] as $r ) {
								$w = strtok( (string) $r[ $csv['status'] ], ' ' );
								if ( isset( $c[ $w ] ) ) {
									$c[ $w ]++;
								}
							}
						}
						$labels = [
							'Sent'    => __( 'Sent', 'simple-csv-mailer' ),
							'Skipped' => __( 'Skipped', 'simple-csv-mailer' ),
							'Invalid' => __( 'Invalid', 'simple-csv-mailer' ),
							'Failed'  => __( 'Failed', 'simple-csv-mailer' ),
						];
						$result = [];
						foreach ( $c as $k => $v ) {
							$result[] = $v . ' ' . $labels[ $k ];
						}
						$txt = preg_replace( '/\.csv$/', '.txt', $name );
						?>
						<tr>
							<td><?php echo esc_html( $name ); ?></td>
							<td><?php echo esc_html( implode( ', ', $result ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( self::url( [ 'download' => $name ] ), 'scm_download' ) ); ?>"><?php esc_html_e( 'CSV', 'simple-csv-mailer' ); ?></a>
								<?php if ( self::path( $txt ) ) : ?>
									| <a href="<?php echo esc_url( wp_nonce_url( self::url( [ 'download' => $txt ] ), 'scm_download' ) ); ?>"><?php esc_html_e( 'Email (txt)', 'simple-csv-mailer' ); ?></a>
								<?php endif; ?>
							</td>
							<td class="scm-actions">
								<?php if ( $c['Failed'] ) : ?>
									<form method="post" class="scm-inline"><?php wp_nonce_field( 'scm_form' ); ?><input type="hidden" name="scm_action" value="retry"><input type="hidden" name="file" value="<?php echo esc_attr( $name ); ?>"><button class="button button-small"><?php esc_html_e( 'Retry failed', 'simple-csv-mailer' ); ?></button></form>
								<?php endif; ?>
								<form method="post" class="scm-inline" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this file and its email record?', 'simple-csv-mailer' ) ) ); ?>)"><?php wp_nonce_field( 'scm_form' ); ?><input type="hidden" name="scm_action" value="delete"><input type="hidden" name="file" value="<?php echo esc_attr( $name ); ?>"><button class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'simple-csv-mailer' ); ?></button></form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $pending ) : ?>
				<form method="post" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this list?', 'simple-csv-mailer' ) ) ); ?>)">
					<?php wp_nonce_field( 'scm_form' ); ?>
					<input type="hidden" name="scm_action" value="delete">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="scm-del"><?php esc_html_e( 'Delete an unsent list', 'simple-csv-mailer' ); ?></label></th>
							<td>
								<select name="file" id="scm-del">
									<?php foreach ( array_keys( $pending ) as $name ) : ?>
										<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
								<button class="button button-link-delete"><?php esc_html_e( 'Delete', 'simple-csv-mailer' ); ?></button>
							</td>
						</tr>
					</table>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
