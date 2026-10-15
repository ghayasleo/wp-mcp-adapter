<?php
/**
 * Plugin Name: MCP Site Abilities
 * Description: Exposes WordPress core abilities to the MCP Adapter default server and registers extra abilities for theme listing/activation and wp-content file management.
 * Version:     1.1.1
 * Requires Plugins: mcp-adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 1. Mark core abilities as MCP-public
 * ---------------------------------------------------------------------- */

add_filter( 'wp_register_ability_args', 'msa_enable_core_abilities_mcp', 10, 2 );

/**
 * Flag core abilities as MCP-public.
 *
 * @param array  $args Ability registration args.
 * @param string $name Ability name.
 * @return array
 */
function msa_enable_core_abilities_mcp( array $args, string $name ): array {
	$public = array(
		'core/get-site-info',
		'core/get-user-info',
		'core/get-environment-info',
	);

	if ( in_array( $name, $public, true ) ) {
		$args['meta']['mcp']['public'] = true;
	}

	return $args;
}

/* -------------------------------------------------------------------------
 * 2. Custom abilities: themes + wp-content files
 * ---------------------------------------------------------------------- */

final class MSA_Site_Abilities {

	const NS       = 'site';
	const CATEGORY = 'site-management';

	/** Roots exposed to the file abilities, relative to wp-content. */
	private static $roots = array(
		'themes'     => 'themes',
		'plugins'    => 'plugins',
		'mu-plugins' => 'mu-plugins',
		'uploads'    => 'uploads',
	);

	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => 'Site Management',
				'description' => 'Theme listing/activation and wp-content file management.',
			)
		);
	}

	public static function can() {
		return current_user_can( 'manage_options' );
	}

	private static function meta( $title, $readonly = false, $destructive = false, $idempotent = false ) {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations'  => array(
				'title'           => $title,
				'readOnlyHint'    => $readonly,
				'destructiveHint' => $destructive,
				'idempotentHint'  => $idempotent,
				'openWorldHint'   => false,
			),
		);
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$root_enum = array_keys( self::$roots );

		wp_register_ability(
			self::NS . '/themes-list',
			array(
				'label'               => 'List Themes',
				'description'         => 'List all installed themes with stylesheet slug, name, version, parent theme, and whether each is active. Use the stylesheet slug with theme-activate and as the directory under root "themes" for file abilities.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'active' => array( 'type' => 'string' ),
						'themes' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'themes_list' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'meta'                => self::meta( 'List Themes', true, false, true ),
			)
		);

		wp_register_ability(
			self::NS . '/theme-activate',
			array(
				'label'               => 'Activate Theme',
				'description'         => 'Activate an installed theme by its stylesheet slug (from themes-list).',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'stylesheet' => array( 'type' => 'string' ),
					),
					'required'             => array( 'stylesheet' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'active'   => array( 'type' => 'string' ),
						'previous' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'theme_activate' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'meta'                => self::meta( 'Activate Theme', false, false, true ),
			)
		);

		wp_register_ability(
			self::NS . '/files-list',
			array(
				'label'               => 'List wp-content Files',
				'description'         => 'List files and directories under a wp-content root (themes, plugins, mu-plugins, uploads). Pass a relative path inside the root (e.g. "twentytwentyfive" or "twentytwentyfive/templates") and optional recursion depth.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'root'  => array(
							'type'        => 'string',
							'enum'        => $root_enum,
							'description' => 'Which wp-content folder to list.',
						),
						'path'  => array(
							'type'        => 'string',
							'description' => 'Relative path inside the root. Empty for the root itself.',
							'default'     => '',
						),
						'depth' => array(
							'type'        => 'integer',
							'description' => 'How many directory levels to descend. 1 = immediate children only.',
							'default'     => 1,
							'minimum'     => 1,
							'maximum'     => 6,
						),
					),
					'required'             => array( 'root' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'root'    => array( 'type' => 'string' ),
						'path'    => array( 'type' => 'string' ),
						'entries' => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'files_list' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'meta'                => self::meta( 'List wp-content Files', true, false, true ),
			)
		);

		wp_register_ability(
			self::NS . '/file-read',
			array(
				'label'               => 'Read wp-content File',
				'description'         => 'Read a file under a wp-content root. Returns content plus a sha1 to pass back to file-write for safe updates. Binary files are returned base64-encoded.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'root' => array( 'type' => 'string', 'enum' => $root_enum ),
						'path' => array( 'type' => 'string', 'description' => 'Relative file path inside the root, e.g. "twentytwentyfive/functions.php".' ),
					),
					'required'             => array( 'root', 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'path'     => array( 'type' => 'string' ),
						'size'     => array( 'type' => 'integer' ),
						'sha1'     => array( 'type' => 'string' ),
						'encoding' => array( 'type' => 'string' ),
						'content'  => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'file_read' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'meta'                => self::meta( 'Read wp-content File', true, false, true ),
			)
		);

		wp_register_ability(
			self::NS . '/file-write',
			array(
				'label'               => 'Write wp-content File',
				'description'         => 'Create or overwrite a file under a wp-content root. Parent directories are created. PHP files are syntax-checked with "php -l" when available and the write is rejected on a syntax error. Pass expected_sha1 from a prior file-read to refuse the write if the file changed meanwhile. A .bak copy of the previous version is kept unless backup is false.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'root'          => array( 'type' => 'string', 'enum' => $root_enum ),
						'path'          => array( 'type' => 'string' ),
						'content'       => array( 'type' => 'string' ),
						'encoding'      => array( 'type' => 'string', 'enum' => array( 'utf8', 'base64' ), 'default' => 'utf8' ),
						'expected_sha1' => array( 'type' => 'string', 'description' => 'sha1 of the current file as returned by file-read. Optional.' ),
						'backup'        => array( 'type' => 'boolean', 'default' => true ),
					),
					'required'             => array( 'root', 'path', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'path'    => array( 'type' => 'string' ),
						'size'    => array( 'type' => 'integer' ),
						'sha1'    => array( 'type' => 'string' ),
						'backup'  => array( 'type' => 'string' ),
						'created' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'file_write' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'meta'                => self::meta( 'Write wp-content File', false, true, true ),
			)
		);

		wp_register_ability(
			self::NS . '/file-delete',
			array(
				'label'               => 'Delete wp-content File',
				'description'         => 'Delete a single file under a wp-content root. Directories are refused. Cannot delete this plugin file or anything inside the mcp-adapter plugin.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'root' => array( 'type' => 'string', 'enum' => $root_enum ),
						'path' => array( 'type' => 'string' ),
					),
					'required'             => array( 'root', 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'path'    => array( 'type' => 'string' ),
						'deleted' => array( 'type' => 'boolean' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'file_delete' ),
				'permission_callback' => array( __CLASS__, 'can' ),
				'meta'                => self::meta( 'Delete wp-content File', false, true, true ),
			)
		);
	}

	/* ---------- helpers ---------- */

	private static function resolve( $root, $path, $must_exist = true ) {
		if ( ! isset( self::$roots[ $root ] ) ) {
			return new WP_Error( 'msa_bad_root', 'Unknown root.' );
		}
		$base = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . self::$roots[ $root ] );
		$path = wp_normalize_path( (string) $path );
		$path = ltrim( $path, '/' );

		if ( strpos( $path, "\0" ) !== false || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return new WP_Error( 'msa_bad_path', 'Path traversal is not allowed.' );
		}

		$full = $path === '' ? $base : $base . '/' . $path;

		if ( $must_exist ) {
			$real = realpath( $full );
			if ( false === $real ) {
				return new WP_Error( 'msa_not_found', 'Path does not exist: ' . $path );
			}
			$real = wp_normalize_path( $real );
		} else {
			$parent = realpath( dirname( $full ) );
			$real   = $parent ? wp_normalize_path( $parent ) . '/' . basename( $full ) : $full;
		}

		$real_base = wp_normalize_path( realpath( $base ) ?: $base );
		if ( strpos( $real, $real_base . '/' ) !== 0 && $real !== $real_base ) {
			return new WP_Error( 'msa_outside_root', 'Resolved path is outside the allowed root.' );
		}

		return $real;
	}

	/** Refuse to touch this file (works as mu-plugin or regular plugin) or the mcp-adapter plugin. */
	private static function protected_path( $full ) {
		$self    = wp_normalize_path( realpath( __FILE__ ) ?: __FILE__ );
		$adapter = wp_normalize_path( WP_PLUGIN_DIR . '/mcp-adapter/' );
		return $full === $self || strpos( $full, $adapter ) === 0;
	}

	private static function is_binary( $content ) {
		if ( '' === $content ) {
			return false;
		}
		if ( strpos( $content, "\0" ) !== false ) {
			return true;
		}
		return ! ( function_exists( 'mb_check_encoding' ) ? mb_check_encoding( $content, 'UTF-8' ) : preg_match( '//u', $content ) );
	}

	private static function php_lint( $file ) {
		if ( ! function_exists( 'exec' ) ) {
			return true; // cannot check; allow
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return true;
		}
		$php = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
		$out = array();
		$rc  = 1;
		@exec( escapeshellarg( $php ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1', $out, $rc );
		if ( 0 !== $rc && ! empty( $out ) ) {
			$joined = implode( "\n", $out );
			if ( stripos( $joined, 'not found' ) !== false || 127 === $rc ) {
				return true; // binary unavailable; allow
			}
			return new WP_Error( 'msa_php_syntax', 'PHP syntax error: ' . $joined );
		}
		return true;
	}

	private static function rel( $root, $full ) {
		$base = wp_normalize_path( realpath( trailingslashit( WP_CONTENT_DIR ) . self::$roots[ $root ] ) );
		return ltrim( substr( $full, strlen( $base ) ), '/' );
	}

	/* ---------- callbacks ---------- */

	public static function themes_list( $input ) {
		$active = get_stylesheet();
		$out    = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'stylesheet' => $slug,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : '',
				'is_block'   => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
				'active'     => $slug === $active,
				'dir'        => wp_normalize_path( $theme->get_stylesheet_directory() ),
			);
		}
		return array(
			'active' => $active,
			'themes' => $out,
		);
	}

	public static function theme_activate( $input ) {
		$theme = wp_get_theme( $input['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'msa_theme_missing', 'Theme not found.' );
		}
		if ( $theme->errors() ) {
			return $theme->errors();
		}
		$previous = get_stylesheet();
		switch_theme( $theme->get_stylesheet() );
		return array(
			'active'   => get_stylesheet(),
			'previous' => $previous,
		);
	}

	public static function files_list( $input ) {
		$root  = $input['root'];
		$path  = isset( $input['path'] ) ? $input['path'] : '';
		$depth = isset( $input['depth'] ) ? max( 1, min( 6, (int) $input['depth'] ) ) : 1;

		$full = self::resolve( $root, $path );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		if ( ! is_dir( $full ) ) {
			return new WP_Error( 'msa_not_dir', 'Path is not a directory.' );
		}

		$entries = array();
		self::walk( $full, $root, $depth, $entries );

		return array(
			'root'    => $root,
			'path'    => self::rel( $root, $full ),
			'entries' => $entries,
		);
	}

	private static function walk( $dir, $root, $depth, &$entries ) {
		$items = @scandir( $dir );
		if ( ! $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$p         = $dir . '/' . $item;
			$is_dir    = is_dir( $p );
			$entries[] = array(
				'path'     => self::rel( $root, wp_normalize_path( $p ) ),
				'type'     => $is_dir ? 'dir' : 'file',
				'size'     => $is_dir ? null : (int) filesize( $p ),
				'modified' => gmdate( 'c', (int) filemtime( $p ) ),
			);
			if ( $is_dir && $depth > 1 ) {
				self::walk( $p, $root, $depth - 1, $entries );
			}
		}
	}

	public static function file_read( $input ) {
		$full = self::resolve( $input['root'], $input['path'] );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		if ( ! is_file( $full ) ) {
			return new WP_Error( 'msa_not_file', 'Path is not a file.' );
		}
		$size = (int) filesize( $full );
		if ( $size > 5 * 1024 * 1024 ) {
			return new WP_Error( 'msa_too_large', 'File larger than 5 MB.' );
		}
		$content = file_get_contents( $full );
		if ( false === $content ) {
			return new WP_Error( 'msa_read_failed', 'Could not read file.' );
		}
		$binary = self::is_binary( $content );
		return array(
			'path'     => self::rel( $input['root'], $full ),
			'size'     => $size,
			'sha1'     => sha1( $content ),
			'encoding' => $binary ? 'base64' : 'utf8',
			'content'  => $binary ? base64_encode( $content ) : $content,
		);
	}

	public static function file_write( $input ) {
		$full = self::resolve( $input['root'], $input['path'], false );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		if ( self::protected_path( $full ) ) {
			return new WP_Error( 'msa_protected', 'Refusing to write this plugin file or anything inside mcp-adapter.' );
		}
		if ( is_dir( $full ) ) {
			return new WP_Error( 'msa_is_dir', 'Path is a directory.' );
		}

		$content = (string) $input['content'];
		if ( isset( $input['encoding'] ) && 'base64' === $input['encoding'] ) {
			$decoded = base64_decode( $content, true );
			if ( false === $decoded ) {
				return new WP_Error( 'msa_bad_base64', 'Invalid base64 content.' );
			}
			$content = $decoded;
		}

		$exists = is_file( $full );
		if ( $exists && ! empty( $input['expected_sha1'] ) ) {
			$current = sha1( (string) file_get_contents( $full ) );
			if ( $current !== $input['expected_sha1'] ) {
				return new WP_Error( 'msa_sha_mismatch', 'File changed since it was read. Re-read and retry.', array( 'current_sha1' => $current ) );
			}
		}

		$dir = dirname( $full );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'msa_mkdir_failed', 'Could not create parent directory.' );
		}

		// Syntax check PHP before touching the live file.
		if ( preg_match( '/\.php$/i', $full ) ) {
			$tmp = tempnam( get_temp_dir(), 'msa-lint' );
			if ( false === $tmp ) {
				return new WP_Error( 'msa_tmp_failed', 'Could not create temp file for syntax check.' );
			}
			file_put_contents( $tmp, $content );
			$lint = self::php_lint( $tmp );
			@unlink( $tmp );
			if ( is_wp_error( $lint ) ) {
				return $lint;
			}
		}

		$backup = '';
		$do_bak = ! isset( $input['backup'] ) || false !== $input['backup'];
		if ( $exists && $do_bak ) {
			$backup = $full . '.bak';
			@copy( $full, $backup );
		}

		if ( false === file_put_contents( $full, $content, LOCK_EX ) ) {
			return new WP_Error( 'msa_write_failed', 'Could not write file.' );
		}

		return array(
			'path'    => self::rel( $input['root'], wp_normalize_path( $full ) ),
			'size'    => strlen( $content ),
			'sha1'    => sha1( $content ),
			'backup'  => $backup ? self::rel( $input['root'], wp_normalize_path( $backup ) ) : '',
			'created' => ! $exists,
		);
	}

	public static function file_delete( $input ) {
		$full = self::resolve( $input['root'], $input['path'] );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		if ( self::protected_path( $full ) ) {
			return new WP_Error( 'msa_protected', 'Refusing to delete this plugin file or anything inside mcp-adapter.' );
		}
		if ( ! is_file( $full ) ) {
			return new WP_Error( 'msa_not_file', 'Only single files can be deleted.' );
		}
		if ( ! @unlink( $full ) ) {
			return new WP_Error( 'msa_delete_failed', 'Could not delete file.' );
		}
		return array(
			'path'    => self::rel( $input['root'], $full ),
			'deleted' => true,
		);
	}
}

MSA_Site_Abilities::init();