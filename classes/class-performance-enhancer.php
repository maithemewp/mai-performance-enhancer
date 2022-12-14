<?php

/**
 * Manipulates the final output of the dom for performance tweaks.
 *
 * @version 0.1.0
 *
 * @return void
 */
class Mai_Performance_Enhancer {
	protected $scripts;
	protected $settings;
	protected $data;

	/**
	 * Constructs the class.
	 *
	 * @return void
	 */
	function __construct() {
		// Sets props.
		$this->scripts  = [];
		$this->settings = apply_filters( 'mai_performance_enhancer_settings',
			[
				'cache_headers'    => true,
				'ttl_homepage'     => '60', // in seconds.
				'ttl_inner'        => '180', // in seconds.
				'preload_header'   => true,
				'tidy'             => true,
				'lazy_images'      => true,
				'lazy_iframes'     => true,
				'move_scripts'     => true,
			]
		);

		// Get data. TODO: Come from settings.
		$this->data = apply_filters( 'mai_performance_enhancer_data',
			[
				'preconnect_links' => '',
				'prefetch_links'   => '',
				'scripts'          => '',
			]
		);

		// Sanitize settings.
		$this->settings['cache_headers']    = rest_sanitize_boolean( $this->settings['cache_headers'] );
		$this->settings['ttl_homepage']     = absint( $this->settings['ttl_homepage'] );
		$this->settings['ttl_inner']        = absint( $this->settings['ttl_inner'] );
		$this->settings['preload_header']   = rest_sanitize_boolean( $this->settings['preload_header'] );
		$this->settings['tidy']             = rest_sanitize_boolean( $this->settings['tidy'] );
		$this->settings['lazy_images']      = rest_sanitize_boolean( $this->settings['lazy_images'] );
		$this->settings['lazy_iframes']     = rest_sanitize_boolean( $this->settings['lazy_iframes'] );
		$this->settings['move_scripts']     = rest_sanitize_boolean( $this->settings['move_scripts'] );

		// Sanitize data.
		$this->data['preconnect_links'] = wp_kses_post( trim( $this->data['preconnect_links'] ) );
		$this->data['prefetch_links']   = wp_kses_post( trim( $this->data['prefetch_links'] ) );
		$this->data['scripts']          = wp_kses_post( trim( $this->data['scripts'] ) );

		$this->hooks();
	}

	/**
	 * Runs hooks.
	 *
	 * @return void
	 */
	function hooks() {
		add_action( 'after_setup_theme', [ $this, 'start' ], 999998 );
		add_action( 'shutdown',          [ $this, 'end' ], 999999 );
	}

	/**
	 * Starts output buffering.
	 *
	 * @return void
	 */
	function start() {
		ob_start( [ $this, 'callback' ] );
	}

	/**
	 * Appends div entry point for JS code.
	 * Ends output buffering.
	 *
	 * @return void
	 */
	function end() {
		/**
		 * I hope this is right.
		 *
		 * @link https://stackoverflow.com/questions/7355356/whats-the-difference-between-ob-flush-and-ob-end-flush
		 */
		if ( ob_get_length() ) {
			// ob_end_flush();
			ob_flush();
		}
	}

	/**
	 * Buffer callback.
	 *
	 * @param string $buffer The full dom markup.
	 *
	 * @return string
	 */
	function callback( $buffer ) {
		if ( ! $buffer ) {
			return $buffer;
		}

		// Bail if XML.
		if ( false !== strpos( trim( $buffer ), '<?xml' ) ) {
			return $buffer;
		}

		// Sets caching headers.
		$this->do_cache_headers();

		// Adds existing preloads to header.
		$this->do_preload_header( $buffer );

		// Tidy.
		$buffer = $this->do_tidy( $buffer );

		// Gravatar.
		$buffer = $this->do_gravatar( $buffer );

		// Common replacements.
		$buffer = $this->do_common( $buffer );

		// Adds preconnect, and dns-prefetch links.
		$buffer = $this->do_pp( $buffer );

		// Gets DOMDocument.
		$dom = $this->get_dom( $buffer );

		// Bail if no dom.
		if ( ! $dom ) {
			return $buffer;
		}

		// Sets up new scripts.
		$this->setup_scripts( $dom );

		// Check for required elements.
		$head = $dom->getElementsByTagName( 'head' );
		$body = $dom->getElementsByTagName( 'body' );
		$head = $head && $head->item(0) ? $head->item(0) : false;
		$body = $body && $body->item(0) ? $body->item(0) : false;

		// Bail if not head and body.
		if ( ! ( $head && $body ) ) {
			return $buffer;
		}

		// Lazy load images.
		if ( $this->settings['lazy_images'] ) {
			$main = $body->getElementsByTagName( 'main' );
			$main = $main && $main->item(0) ? $main->item(0) : false;

			if ( $main ) {
				$images = $main->getElementsByTagName( 'img' );

				if ( $images->length ) {
					foreach ( $images as $node ) {
						static $first = true;

						// Skip the first, likely above the fold.
						if ( $first ) {
							continue;
						}

						$first = false;

						// Skip if loading attribute already exists.
						if ( $node->getAttribute( 'loading' ) ) {
							continue;
						}

						// Skip if no-lazy class.
						if ( in_array( 'no-lazy', explode( ' ', $node->getAttribute( 'class' ) ) ) ) {
							continue;
						}

						$node->setAttribute( 'loading', 'lazy' );
					}
				}
			}
		}

		// Lazy load iframes.
		if ( $this->settings['lazy_iframes'] ) {
			$iframes = $head->getElementsByTagName( 'iframes' );

			if ( $iframes->length ) {
				foreach ( $iframes as $node ) {
					// Skip if loading attribute already exists.
					if ( $node->getAttribute( 'loading' ) ) {
						continue;
					}

					// Skip if no-lazy class.
					if ( in_array( 'no-lazy', explode( ' ', $node->getAttribute( 'class' ) ) ) ) {
						continue;
					}

					$node->setAttribute( 'loading', 'lazy' );
				}
			}
		}

		// Move scripts.
		if ( $this->settings['move_scripts'] ) {
			$head_scripts = $head->getElementsByTagName( 'script' );
			$body_scripts = $body->getElementsByTagName( 'script' );

			if ( $head_scripts->length ) {
				$this->handle_scripts( $head_scripts );
			}

			if ( $body_scripts->length ) {
				$this->handle_scripts( $body_scripts );
			}
		}

		// Gets main site-container.
		$container = $dom->getElementById( 'top' );

		// if ( $container && $this->scripts ) {
		if ( $container && $this->scripts ) {
			// Reverse, because insertBefore will put them in opposite order.
			$this->scripts = array_reverse( $this->scripts );

			foreach ( $this->scripts as $object ) {
				/**
				 * Moves script(s) after this element. There is no insertAfter() in PHP ¯\_(ツ)_/¯.
				 * No need to `removeChild` first, since this moves the actual node.
				 *
				 * @link https://gist.github.com/deathlyfrantic/cd8d7ef8ba91544cdf06
				 */
				$container->parentNode->insertBefore( $object, $container->nextSibling );
			}
		}

		// Save HTML.
		$buffer = $dom->saveHTML();

		return $buffer;
	}

	/**
	 * Sets cache headers.
	 *
	 * @return void
	 */
	function do_cache_headers() {
		if ( ! $this->settings['cache_headers'] ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		header_remove( 'Cache-Control' );

		$max_age    = is_front_page() ? $this->settings['ttl_homepage'] : $this->settings['ttl_inner'];
		$revalidate = $max_age * 2;

		header( "Cache-Control: public, max-age={$max_age}, must-revalidate, stale-while-revalidate={$revalidate}, stale-if-error=14400" );
	}

	/**
	 * Preload links in HTTP headers.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_preload_header( $buffer ) {
		if ( ! $this->settings['preload_header'] ) {
			return;
		}

		preg_match_all( "#<link rel=\"preload\" href=\"(.*?)\" as=\"(.*?)\" (.*?)>#s", $buffer, $links, PREG_PATTERN_ORDER );

		if ( isset( $links[1] ) && is_array( $links[1] ) && count( $links[1] ) ) {
			foreach ( $links[1] as $key => $link ) {
				if ( ! isset( $links[2][$key] ) ) {
					continue;
				}

				header( "Link: <{$link}>; rel=preload; as={$links[2][$key]}; crossorigin", false );
			}
		}
	}

	/**
	 * Gets DOMDocument.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return DOMDocument
	 */
	function get_dom( $buffer ) {
		// Create the new document.
		$dom = new DOMDocument();

		// Modify state.
		$libxml_previous_state = libxml_use_internal_errors( true );

		// Load the content in the document HTML.
		$dom->loadHTML( mb_convert_encoding( $buffer, 'HTML-ENTITIES', 'UTF-8' ) );

		// Handle errors.
		libxml_clear_errors();

		// Restore.
		libxml_use_internal_errors( $libxml_previous_state );

		return $dom;
	}

	/**
	 * Handles scripts before closing body tag.
	 *
	 * @param array       $scripts Array of script element nodes.
	//  * @param DOMDocument $dom     The full dom object.
	//  * @param DOMNOde     $body    The body dom node.
	 *
	 * @return void
	 */
	function handle_scripts( $scripts ) {
		static $sources = [];

		foreach ( $scripts as $node ) {
			$type = $node->getAttribute( 'type' );
			$src  = $node->getAttribute( 'src' );

			if ( $type && 'application/ld+json' === $type ) {
				continue;
			}

			// Check sources.
			if ( $src ) {
				$skips = [
					'plugins/mai-engine',
					'plugins/wp-rocket',
					// 'googlesyndication',
				];

				// Skip if we already have this script.
				// This happens with Twitter embeds and similar.
				if ( in_array( $src, $sources ) ) {
					continue;
				}

				// Skip scripts we don't want to move.
				if ( $this->has_string( $src, $skips ) ) {
					continue;
				}

				$sources[] = $src;
			}

			// Add scripts to move later.
			$this->scripts[] = $node;
		}
	}

	/**
	 * Pretty Printing
	 *
	 * @author  Chris Bratlien
	 *
	 * @param   mixed $obj
	 * @param   string $label
	 *
	 * @return  null
	 */
	function pretty_print( $obj, $label = '' ) {
		$data = json_encode( print_r( $obj,true ) );
		?>
		<style type="text/css">
			#maiLogger {
				position: absolute;
				top: 30px;
				right: 0px;
				border-left: 4px solid #bbb;
				padding: 6px;
				background: white;
				color: #444;
				z-index: 999;
				font-size: 1.2rem;
				width: 40vw;
				height: calc( 100vh - 30px );
				overflow: scroll;
			}
		</style>
		<script type="text/javascript">
			var doStuff = function() {
				var obj    = <?php echo $data; ?>;
				var logger = document.getElementById('maiLogger');
				if ( ! logger ) {
					logger = document.createElement('div');
					logger.id = 'maiLogger';
					document.body.appendChild(logger);
				}
				////console.log(obj);
				var pre = document.createElement('pre');
				var h2  = document.createElement('h2');
				pre.innerHTML = obj;
				h2.innerHTML  = '<?php echo addslashes($label); ?>';
				logger.appendChild(h2);
				logger.appendChild(pre);
			};
			window.addEventListener( "DOMContentLoaded", doStuff, false );
		</script>
		<?php
	}

	/**
	 * Check if a string contains at least one specified string.
	 * Takens from Mai Engine `mai_has_string()`.
	 *
	 * @since TBD
	 *
	 * @param string|array $needle   String or array of strings to check for.
	 * @param string|array $haystack String to check in.
	 *
	 * @return string
	 */
	function has_string( $needle, $haystack ) {
		if ( is_array( $needle ) ) {
			foreach ( $needle as $string ) {
				if ( ! $this->check_string( $string, $haystack ) ) {
					continue;
				}

				return true;
			}

			return false;
		}

		return $this->check_string( $needle, $haystack );
	}

	/**
	 * Checks string in haystack.
	 *
	 * @since TBD
	 *
	 * @param string       $string   String to check for.
	 * @param string|array $haystack String or array of strings to check in.
	 *
	 * @return void
	 */
	function check_string( $string, $haystack ) {
		if ( is_array( $haystack ) ) {
			foreach ( $haystack as $stack ) {
				if ( false !== strpos( $stack, $string ) ) {
					return true;
				}
			}
		} elseif ( false !== strpos( $haystack, $string ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a string starts with another string.
	 *
	 * @param string $haystack The full string.
	 * @param string $needle   The string to check.
	 *
	 * @return bool
	 */
	function string_starts_with( $haystack, $needle ) {
		// PHP 8 has this already.
		if ( function_exists( 'str_starts_with' ) ) {
			return str_starts_with( $haystack, $needle );
		}

		return '' !== (string) $needle && strncmp( $haystack, $needle, 0 === strlen( $needle ) );
	}

	/**
	 * Gets tidy HTML.
	 *
	 * @link Tidy (v5.6.0 - https://api.html-tidy.org/tidy/quickref_5.6.0.html)
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_tidy( $buffer ) {
		if ( ! $this->settings['tidy'] ) {
			return $buffer;
		}

		if ( ! class_exists( 'tidy' ) || isset( $_GET['notidy'] ) ) {
			return $buffer;
		}

		// Tidy Configuration Options
		$config = [
			'alt-text'                    => 'Image',
			'break-before-br'             => 1,
			'clean'                       => 1,
			'doctype'                     => 'html5',
			'drop-empty-elements'         => 0,
			'drop-proprietary-attributes' => 0,
			'hide-comments'               => 0,
			'indent-cdata'                => 0,
			'indent-spaces'               => 4,
			'indent'                      => 1,
			'merge-divs'                  => 0,
			'merge-spans'                 => 0,
			'new-blocklevel-tags'         => 'fb:like, fb:send, fb:comments, fb:activity, fb:recommendations, fb:like-box, fb:login-button, fb:facepile, fb:live-stream, fb:fan, fb:pile, article, aside, bdi, command, details, summary, figure, figcaption, footer, header, hgroup, mark, meter, nav, picture, progress, ruby, rt, rp, section, span, time, wbr, audio, video, source, embed, track, canvas, datalist, keygen, output, amp-ad, amp-analytics, ampstyle, amp-img, amp-instagram, amp-twitter, amp-youtube, amp-iframe,ad-img, glomex-player',
			'new-empty-tags'              => 'a,b,li,strong,span,i,div',
			'output-xhtml'                => 1,
			'wrap'                        => 0,
		];

		$tidy = new tidy();
		$tidy->parseString( $buffer, $config, 'utf8' );
		$tidy->cleanRepair();

		$tidy   = $tidy . "\n<!-- HTML Tidy engine enabled -->";
		$buffer = $tidy;

		// CDATA cleanups.
		$find = [
			'/*<![CDATA[*/',
			'/*//]]>*/',
			'//<![CDATA[',
			'//]]>',
			'<![CDATA[',
			']]>',
			'/**/'
		];
		$buffer = str_replace( $find, '', $buffer );

		// HTML5 Mode.
		$buffer = preg_replace(
			[
				"#<!DOCTYPE(.+?)>#s",
				"# xmlns=\"(.+?)\"#s",
				"# (xml|xmlns)\:(.+?)=\"(.+?)\"#s"
			],
			[
			"<!doctype html>",
			"",
			""
			],
			$buffer
		);

		return $buffer;
	}

	/**
	 * Makes sure gravatar is loaded securely.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_gravatar( $buffer ) {
		// For Gravatar
		$buffer = str_ireplace(
			['http://0.gravatar.com', 'http://1.gravatar.com', 'http://2.gravatar.com', 'http://3.gravatar.com', 'http://4.gravatar.com'],
			['https://0.gravatar.com', 'https://1.gravatar.com', 'https://2.gravatar.com', 'https://3.gravatar.com', 'https://4.gravatar.com'],
			$buffer
		);

		return $buffer;
	}

	/**
	 * Handles common replacements
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_common( $buffer ) {
		// Common replacements.
		$find = [
			'<meta http-equiv="content-type" content="text/html; charset=utf-8" />',
			' type=\'text/javascript\'',
			' type="text/javascript"',
			' type=\'text/css\'',
			' type="text/css"',
			' language=\'Javascript\'',
			' language="Javascript"',
			'//<![CDATA[',
			'//]]>',
			'async="true"',
			'async="async"',
			'async=\'true\'',
			'async=\'async\'',
			'http://youtu.be',
			'http://youtube.com',
			'http://www.youtube.com',
			'http://vimeo.com',
			'http://www.vimeo.com',
			'http://dailymotion.com',
			'http://www.dailymotion.com',
			'http://facebook.com',
			'http://www.facebook.com',
			'http://twitter.com',
			'http://www.twitter.com',
		];

		$replace = [
			'<meta charset="utf-8" />',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'async',
			'async',
			'async',
			'async',
			'https://youtu.be',
			'https://www.youtube.com',
			'https://www.youtube.com',
			'https://vimeo.com',
			'https://vimeo.com',
			'https://www.dailymotion.com',
			'https://www.dailymotion.com',
			'https://www.facebook.com',
			'https://www.facebook.com',
			'https://twitter.com',
			'https://twitter.com',
		];

		$buffer = str_ireplace( $find, $replace, $buffer );

		return $buffer;
	}

	/**
	 * Handles preconnect, and dns-prefetch.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_pp( $buffer ) {
		$links = [];

		if ( $this->data['preconnect_links'] ) {
			$preconnect = $this->data['preconnect_links'];
			$preconnect = array_unique( $preconnect );
			$preconnect = array_filter( $preconnect );

			if ( $preconnect ) {
				foreach ( $preconnect as $link ) {
					$links[] = sprintf( '<link rel="preconnect" href="%s" />', esc_url( $link ) );
				}
			}
		}

		if ( $this->data['prefetch_links'] ) {
			$prefetch = $this->data['prefetch_links'];
			$prefetch = array_unique( $prefetch );
			$prefetch = array_filter( $prefetch );

			if ( $prefetch ) {
				foreach ( $prefetch as $link ) {
					$links[] = sprintf( '<link rel="dns-prefetch" href="%s" />', esc_url( $link ) );
				}
			}
		}

		if ( $links ) {
			$links  = implode( PHP_EOL, $links );
			$buffer = str_replace( '<meta charset="UTF-8" />', '<meta charset="UTF-8" />' . PHP_EOL . $links, $buffer );
		}

		return $buffer;
	}

	/**
	 * Adds new scripts to start of script objects.
	 *
	 * @return void
	 */
	function setup_scripts( $dom ) {
		if ( $this->data['scripts'] ) {
			$fragment = $dom->createDocumentFragment();
			$fragment->appendXML( $this->data['scripts'] );

			$this->scripts[] = $fragment;
		}
	}
}

/**
 * Instantiates the class.
 * Add conditionals as needed.
 *
 * @return void
 */
add_action( 'after_setup_theme', function() {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	new Mai_Performance_Enhancer;
});
