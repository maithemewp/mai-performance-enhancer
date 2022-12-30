<?php

/**
 * Manipulates the final output of the dom for performance tweaks.
 *
 * @return void
 */
class Mai_Performance_Enhancer {
	protected $scripts;
	protected $sources;
	protected $styles;
	protected $remove;
	protected $inject;
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
		$this->sources  = [];
		$this->styles   = [];
		$this->remove   = [];
		$this->inject   = '';
		$this->settings = apply_filters( 'mai_performance_enhancer_settings',
			[
				'cache_headers'    => true,
				'ttl_homepage'     => '60', // in seconds.
				'ttl_inner'        => '180', // in seconds.
				'preload_header'   => true,
				'tidy'             => false, // Disable this for now.
				'lazy_images'      => true,
				'lazy_iframes'     => true,
				'move_scripts'     => true,
			]
		);

		// Get data. TODO: Come from settings.
		$this->data = apply_filters( 'mai_performance_enhancer_data',
			[
				'preconnect_links' => '',
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

		// Tidy.
		$buffer = $this->do_tidy( $buffer );

		// Gravatar.
		$buffer = $this->do_gravatar( $buffer );

		// Common replacements.
		$buffer = $this->do_common( $buffer );

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

		// Get main element.
		$main = $body->getElementsByTagName( 'main' );
		$main = $main && $main->item(0) ? $main->item(0) : false;

		if ( $main ) {
			// Lazy load images.
			if ( $this->settings['lazy_images'] ) {
				$this->do_lazy_images( $main );
			}

			// Lazy load iframes.
			if ( $this->settings['lazy_iframes'] ) {
				$this->do_lazy_iframes( $main );
			}
		}

		// Handle scripts.
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

		// Preload headers for server side early hints.
		if ( $this->settings['preload_header'] ) {
			$this->do_preload_headers( $dom );
		}

		// Handle preconnect links.
		if ( $this->sources ) {
			$this->do_preconnects( $dom );
		}

		// Handle styles.
		$this->handle_styles( $dom );

		// Handle injects.
		$this->handle_injects( $dom );

		// Remove nodes.
		$this->remove_nodes();

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
	 * @param array $scripts Array of script element nodes.
	 *
	 * @return void
	 */
	function handle_scripts( $scripts ) {
		// Default scripts to keep.
		$skips = [
			'cache',
			'plugins/autoptimize',
			'plugins/mai-engine',
			'plugins/wp-rocket',
		];

		$remove = [];

		// Filter scripts to skip or remove.
		$skips  = apply_filters( 'mai_performance_enhancer_skip_scripts', $skips );
		$remove = apply_filters( 'mai_performance_enhancer_remove_scripts', $remove );

		// Sanitize.
		$skips  = array_unique( array_map( 'esc_attr', $skips ) );
		$remove = array_unique( array_map( 'esc_attr', $remove ) );

		foreach ( $scripts as $node ) {
			// Skip if parent is noscript tag.
			if ( 'noscript' === $node->parentNode->nodeName ) {
				continue;
			}

			// Get attributes.
			$type  = (string) $node->getAttribute( 'type' );
			$src   = (string) $node->getAttribute( 'src' );
			$inner = trim( (string) $node->textContent );

			// Bail if this is JSON.
			if ( $type && 'application/ld+json' === $type ) {
				continue;
			}

			// Check sources.
			if ( $src ) {
				// Remove node and continue if we already moved this script.
				// This happens with Twitter embeds and similar.
				if ( in_array( $src, $this->sources ) ) {
					$node->parentNode->removeChild( $node );
					continue;
				}

				// Skip scripts we don't want to move.
				if ( $skips && $this->has_string( $skips, $src ) ) {
					continue;
				}

				// Skip and remove scripts.
				if ( $remove && $this->has_string( $remove, $src ) ) {
					$this->remove[] = $node;
					continue;
				}

				// Add to sources.
				$this->sources[] = $src;
			}
			// No source.
			else {
				$skips = array_merge( [ 'no-js' ], $skips );

				// Skip if inline script has text string.
				if ( $inner && $this->has_string( $skips, $inner ) ) {
					continue;
				}

				// Skip and remove scripts.
				if ( $remove && $this->has_string( $remove, $inner ) ) {
					$this->remove[] = $node;
					continue;
				}
			}

			// Check if a nobot script.
			$node = $this->handle_nobots( $node, $src );

			// Add scripts to move later.
			if ( $node ) {
				$this->scripts[] = $node;
			}
		}
	}

	/**
	 * Handles robot scripts.
	 * Adds them to a string to by dynamically created via JS later.
	 *
	 * @param DOMNode $node The script node.
	 * @param string  $src  The script src string.
	 *
	 * @return DOMNode|false
	 */
	function handle_nobots( $node, $src = '' ) {
		static $i = 1;

		$human = [
			'.adthrive',
			'advanced-ads',
			'amazon-adsystem.com',
			'connect.facebook.net',
			'googleadservices.com',
			'googletagmanager.com',
			'pinterest.com',
		];

		// Filter scripts to hide from bots.
		$human = apply_filters( 'mai_performance_enhancer_human_scripts', $human );
		$human = array_unique( array_map( 'esc_attr', $human ) );

		$inner = trim( (string) $node->textContent );

		// This was breaking. Need to check inside script only.
		// $inner = $node->ownerDocument->saveHTML( $node );

		if ( ( $src && $this->has_string( $human, $src ) ) || ( $inner && $this->has_string( $human, $inner ) ) ) {
			// Set var and create script.
			$var           = 'nobot' . $i;
			$this->inject .= sprintf( "var %s = document.createElement( 'script' );%s", $var, PHP_EOL );

			// Set attributes.
			foreach ( $node->attributes as $att ) {
				$this->inject .= sprintf( "%s.setAttribute( '%s', '%s' );", $var, $att->name, $att->value );
			}

			// If no src and has inner HTML, add it.
			if ( ! $src && $inner ) {
				$this->inject .= sprintf( "%s.innerHTML = %s;", $var, json_encode( $inner ) );
			}

			// Insert script.
			$this->inject .= sprintf( 'nobots.parentNode.insertBefore( %s, nobots );', $var );

			// Add to remove array.
			$this->remove[] = $node;

			// Increment counter.
			$i++;

			return false;
		}

		return $node;
	}

	/**
	 * Adds preload headers for early hints.
	 * This must be enabled in Cloudflare to do anything.
	 *
	 * TODO:
	 * Currently does not do anything for images
	 * until we figure out how to handle srcset and sizes attributes.
	 *
	 * @param DOMDocument $dom
	 *
	 * @return void
	 */
	function do_preload_headers( $dom ) {
		$xpath   = new DOMXPath( $dom );
		$preload = $xpath->query( '/html/head/link[@rel="preload"]' );

		if ( $preload->length ) {
			foreach ( $preload as $node ) {
				$href = $node->getAttribute( 'href' );
				$as   = $node->getAttribute( 'as' );

				// Skip images until we find out how to handle srcset/sizes.
				if ( 'image' === $as ) {
					continue;
				}
				// if ( 'image' === $as && ! $href ) {
				// 	$srcset = $node->getAttribute( 'imagesrcset' );
				// 	$array  = explode( ',', $srcset );
				// 	$first  = reset( $array );
				// 	$array  = explode( ' ', $first );
				// 	$first  = reset( $array );
				// 	$href   = esc_url( $first );
				// }

				if ( ! $href && ! $as ) {
					continue;
				}

				// https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Link
				$header = "Link: <{$href}>; rel=preload; as={$as}; crossorigin";
				header( $header, false );
			}
		}
	}

	/**
	 * Adds preconnect and dns-prefetch links to the head.
	 *
	 * @param DOMDocument $dom
	 *
	 * @return void
	 */
	function do_preconnects( $dom ) {
		$links       = [];
		$preconnect  = [];
		$prefetch    = [];
		$links       = [];
		$preconnects = [
			'quantcast' => [
				'https://cmp.quantcast.com',
				'https://secure.quantserve.com',
			],
			'google-analytics' => [
				'https://www.google-analytics.com',
			],
			'googletagmanager' => [
				'https://www.googletagmanager.com',
			],
			'googlesyndication' => [
				'https://adservice.google.com',
				'https://googleads.g.doubleclick.net',
				'https://pagead2.googlesyndication.com',
				'https://securepubads.g.doubleclick.net',
				'https://tpc.googlesyndication.com',
				'https://www.googletagservices.com',
			],
			'complex.com' => [
				'https://media.complex.com',
			],
			'convertkit.com' => [
				'https://f.convertkit.com',
			],
			// OptinMonster.
			'omappapi.com' => [
				'https://a.omappapi.com',
				'https://api.omappapi.com',
			],
			// Jetpack.
			'stats.wp' => [
				'https://s.w.org',
				'https://stats.wp.com',
			],
			'taboola' => [
				'https://cdn.taboola.com',
			],
		];

		// Allow filtering.
		$preconnects = apply_filters( 'mai_performance_enhancer_preconnects', $preconnects );

		if ( $preconnects ) {
			foreach ( $preconnects as $key => $srcs ) {
				if ( ! $this->has_string( $key, $this->sources ) ) {
					continue;
				}

				$links[] = $key;
			}

			$links = array_unique( $links );

			if ( $links ) {
				foreach ( $links as $key ) {
					foreach ( $preconnects[ $key ] as $src ) {
						$atts         = 'googlesyndication' === $key ? 'crossorigin="anonymous" ' : '';
						$preconnect[] = sprintf( '<link rel="preconnect" href="%s" %s/>%s', $src, $atts, PHP_EOL );
						// Fallback for Firefox still not supporting preconnect.
						$prefetch[]   = sprintf( '<link rel="dns-prefetch" href="%s"/>%s', $src, PHP_EOL );
					}
				}
			}

			$array = array_merge( $preconnect, $prefetch );

			if ( $array ) {
				$metas    = $dom->getElementsByTagName( 'meta' );
				$meta     = $metas->item(0);
				$string   = PHP_EOL . trim( implode( '', $array ) );
				$fragment = $dom->createDocumentFragment();
				$fragment->appendXML( $string );
				$this->insertafter( $fragment, $meta );
			}
		}
	}

	/**
	 * Handles moving or removing stylesheets from the head.
	 *
	 * @param DOMDocument $dom
	 *
	 * @return void
	 */
	function handle_styles( $dom ) {
		$xpath  = new DOMXPath( $dom );
		$styles = $xpath->query( '/html/head/link[@rel="stylesheet"]' );

		if ( ! $styles->length ) {
			return;
		}

		$remove = [
			'css/classic-themes',
		];

		// Woo blocks.
		if ( class_exists( 'WooCommerce' ) ) {
			$elements = $xpath->query( '//*[starts-with(@data-block-name, "woocommerce/")]' );

			if ( ! $elements->length ) {
				$remove[] = 'wc-blocks';
			}
		}

		// WP Recipe Maker.
		if ( class_exists( 'WPRM_Recipe_Manager' ) ) {
			$elements = $xpath->query( '//*[starts-with(@class, "wprm-recipe")]' );

			if ( ! $elements->length ) {
				$remove[] = 'wp-recipes-maker';
			}
		}

		// Stylesheets.
		$footer = apply_filters( 'mai_performance_enhancer_styles_to_footer', [] );
		$remove = apply_filters( 'mai_performance_enhancer_styles_to_remove', $remove );

		// Sanitize.
		$footer = array_unique( array_map( 'esc_attr', $footer ) );
		$remove = array_unique( array_map( 'esc_attr', $remove ) );

		if ( ! $footer && ! $remove ) {
			return;
		}

		foreach ( $styles as $node ) {
			$href = (string) $node->getAttribute( 'href' );

			if ( ! $href ) {
				continue;
			}

			if ( $footer && $this->has_string( $footer, $href ) ) {
				$this->styles[] = $node;
			}


			if ( $remove && $this->has_string( $remove, $href ) ) {
				$this->remove[] = $node;
			}
		}
	}

	/**
	 * Handles moving or removing stylesheets from the head.
	 *
	 * @param DOMDocument $dom
	 *
	 * @return void
	 */
	function handle_injects( $dom ) {
		// Gets main site-container.
		$container = $dom->getElementById( 'top' );

		// Bail if no container.
		if ( ! $container ) {
			return;
		}

		// Build bot checker and add injected scripts.
		if ( $this->inject ) {
			// JS in HTML.
			$nobots  = '';
			$nobots .= 'window.isBot = (function(){' . PHP_EOL;
				$nobots .= "var agents ='(Googlebot|Googlebot-Mobile|Googlebot-Image|Googlebot-Video|Chrome-Lighthouse|lighthouse|pagespeed|(Google Page Speed Insights)|Bingbot|Applebot|PingdomPageSpeed|GTmetrix|PTST|YLT|Phantomas)';";
				$nobots .= "var regex  = new RegExp( agents, 'i' );" . PHP_EOL;
				$nobots .= "return regex.test( navigator.userAgent );" . PHP_EOL;
			$nobots .= '})();' . PHP_EOL;
			$nobots .= 'if ( ! isBot ) {' . PHP_EOL;
				$nobots .= "const nobots = document.getElementById( 'mai-nobots' );" . PHP_EOL;
				$nobots .= $this->inject . PHP_EOL;
			$nobots .= '}' . PHP_EOL;

			// Build element.
			$element = $dom->createElement( 'script', $nobots );
			$element->setAttribute( 'id', 'mai-nobots' );

			// Add to scripts.
			$this->scripts = array_merge( [ $element ], $this->scripts );
		}

		// Insert scripts.
		if ( $this->scripts ) {
			// Reverse, because insertBefore will put them in opposite order.
			$this->scripts = array_reverse( $this->scripts );

			foreach ( $this->scripts as $node ) {
				$this->insertafter( $node, $container );
			}
		}

		// Insert styles.
		if ( $this->styles ) {
			// Reverse, because insertBefore will put them in opposite order.
			$this->styles = array_reverse( $this->styles );

			foreach ( $this->styles as $node ) {
				$this->insertafter( $node, $container );
			}
		}
	}

	/**
	 * Removes nodes.
	 *
	 * @return void
	 */
	function remove_nodes() {
		if ( ! $this->remove ) {
			return;
		}

		foreach ( $this->remove as $node ) {
			$node->parentNode->removeChild( $node );
		}
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
			'show-body-only'              => 1,
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

	/**
	 * Adds lazy loading to images in the `<main>` content.
	 *
	 * @param DOMNode $main
	 *
	 * @return void
	 */
	function do_lazy_images( $main ) {
		$images = $main->getElementsByTagName( 'img' );

		if ( ! $images->length ) {
			return;
		}

		static $first = true;

		foreach ( $images as $node ) {
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

	/**
	 * Adds lazy loading to iframes in the `<main>` content.
	 *
	 * @param DOMNode $main
	 *
	 * @return void
	 */
	function do_lazy_iframes( $main ) {
		$iframes = $main->getElementsByTagName( 'iframes' );

		if ( ! $iframes->length ) {
			return;
		}

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

	/**
	 * Check if a string contains at least one specified string.
	 * Taken from Mai Engine `mai_has_string()`.
	 *
	 * @since TBD
	 *
	 * @param string|array $needle   String or array of strings to check for.
	 * @param string|array $haystack String or array to check in.
	 *
	 * @return string
	 */
	function has_string( $needle, $haystack ) {
		// Needle array.
		if ( is_array( $needle ) ) {
			foreach ( $needle as $value ) {
				// Haystack array.
				if ( is_array( $haystack ) ) {
					foreach ( $haystack as $stack ) {
						if ( false !== strpos( $stack, $value ) ) {
							return true;
						}
					}
					// Haystack string.
				} else {
					if ( false !== strpos( $haystack, $value ) ) {
						return true;
					}
				}
			}
		}
		// Needle string.
		else {
			// Haystack array.
			if ( is_array( $haystack ) ) {
				foreach ( $haystack as $stack ) {
					if ( false !== strpos( $stack, $needle ) ) {
						return true;
					}
				}
			}
			// Haystack string.
			else {
				if ( false !== strpos( $haystack, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Moves script(s) after this element. There is no insertAfter() in PHP ¯\_(ツ)_/¯.
	 * No need to `removeChild` first, since this moves the actual node.
	 *
	 * @link https://gist.github.com/deathlyfrantic/cd8d7ef8ba91544cdf06
	 *
	 * @param DOMNode    $node    The node.
	 * @param DOMElement $element The element to insert the node after.
	 *
	 * @return void
	 */
	function insertafter( $node, $element ) {
		$element->parentNode->insertBefore( $node, $element->nextSibling );
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
}

/**
 * Instantiates the class.
 * Add conditionals as needed.
 *
 * @return void
 */
add_action( 'after_setup_theme', function() {
	if ( is_admin() ) {
		return;
	}

	if ( wp_doing_ajax() ) {
		return;
	}

	if ( wp_is_json_request() ) {
		return;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	new Mai_Performance_Enhancer;
});
