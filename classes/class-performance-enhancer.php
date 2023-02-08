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

		// Handle lazy loading.
		if ( $this->settings['lazy_images'] || $this->settings['lazy_iframes'] ) {
			$xpath  = new DOMXPath( $dom );
			$lazies = $xpath->query( '//main | footer | //div[@id="top"]/following-sibling::*[not(self::script or self::style or self::link)]' );

			if ( $lazies->length ) {
				foreach ( $lazies as $lazy ) {
					// Lazy load images.
					if ( $this->settings['lazy_images'] ) {
						$this->do_lazy_images( $lazy );
					}

					// Lazy load iframes.
					if ( $this->settings['lazy_iframes'] ) {
						$this->do_lazy_iframes( $lazy );
					}
				}
			}
		}

		// Handle scripts.
		if ( $this->settings['move_scripts'] ) {
			$head_scripts = $head->getElementsByTagName( 'script' );
			$body_scripts = $body->getElementsByTagName( 'script' );

			if ( $head_scripts->length ) {
				$this->handle_scripts( $head_scripts, true );
			}

			if ( $body_scripts->length ) {
				$this->handle_scripts( $body_scripts, false );
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
		$this->handle_inline_styles( $dom );

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
	 * @param bool  $head    If script comes from the `<head>`.
	 *
	 * @return void
	 */
	function handle_scripts( $scripts, $head ) {
		// Default scripts to skip.
		$skips = [
			'plugins/autoptimize',
			'plugins/mai-engine',
			'plugins/wp-rocket',
		];

		// Body skips. These often generate HTML right where the script is.
		if ( ! $head ) {
			$skips[] = 'convertkit'; // Converkit
			$skips[] = '.ck.page'; // Converkit
			$skips[] = 'surveymonkey';
		}

		$remove = [];

		// Filter scripts to skip or remove.
		$skips  = apply_filters( 'mai_performance_enhancer_skip_scripts', $skips );
		$remove = apply_filters( 'mai_performance_enhancer_remove_scripts', $remove );

		// Sanitize.
		$skips  = array_unique( array_map( 'esc_attr', $skips ) );
		$remove = array_unique( array_map( 'esc_attr', $remove ) );

		// Too general to check inner.
		$src_skips   = $skips;
		$src_skips[] = 'cache';

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

			// Remove type.
			if ( $type ) {
				$node->removeAttribute( 'type' );
				$node->normalize();
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
				if ( $src_skips && $this->has_string( $src_skips, $src ) ) {
					continue;
				}

				// Skip and remove scripts.
				if ( $remove && $this->has_string( $remove, $src ) ) {
					$this->remove[] = $node;
					continue;
				}

				// Testing showed removing async showed better performance.
				$node->removeAttribute( 'async' );
				$node->removeAttribute( 'defer' );
				$node->normalize();

				// Add to sources.
				$this->sources[] = $src;
			}
			// No source.
			else {
				// Minify.
				if ( $inner ) {
					$node->textContent = $this->minify_js( $inner );
				}

				// Add Mai/Woo no-js to skips.
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
			'advanced_ads',
			'advanced-ads',
			'advads_',
			'adroll.com',
			'ads-twitter.com',
			'affiliate-wp',
			'amazon-adsystem.com',
			'bing.com',
			'connect.facebook.net',
			'convertflow',
			'complex.com',
			'facebook.net',
			// 'google-analytics.com',
			'googleadservices.com',
			// 'googleoptimize.com',
			'googlesyndication',
			'googletagmanager.com',
			'gstatic.com',
			'hotjar.com',
			'klaviyo.com',
			'omappapi.com',
			'pinterest.com',
			'quantcast',
			'securepubads',
			'slicewp',
			'stats.wp',
			'taboola.com',
		];

		// Filter scripts to hide from bots.
		$human = apply_filters( 'mai_performance_enhancer_human_scripts', $human );
		$human = array_unique( array_map( 'esc_attr', $human ) );

		// Set inner text.
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
				// $this->inject .= sprintf( "%s.innerHTML = %s;", $var, htmlspecialchars( json_encode( $inner ), ENT_QUOTES, 'utf-8' ) );
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
			'ads-twitter.com' => [
				'https://static.ads-twitter.com',
			],
			'adroll.com' => [
				'https://s.adroll.com',
			],
			'adthrive' => [
				'https://ads.adthrive.com',
			],
			'bing.com' => [
				'https://bat.bing.com',
			],
			'cdnjs.cloudflare.com' => [
				'https://cdnjs.cloudflare.com',
			],
			'complex.com' => [
				'https://media.complex.com',
				'https://c.amazon-adsystem.com',
				'https://cdn.confiant-integrations.net',
				'https://micro.rubiconproject.com',
			],
			'convertflow.com' => [
				'https://js.convertflow.co',
				'https://app.convertflow.co',
				'https://assets.convertflow.com',
			],
			'convertkit.com' => [
				'https://f.convertkit.com',
			],
			'facebook.net' => [
				'https://connect.facebook.net',
			],
			'google-analytics' => [
				'https://www.google-analytics.com',
			],
			'googleoptimize' => [
				'https://www.googleoptimize.com',
			],
			'googlesyndication' => [
				'https://adservice.google.com',
				'https://googleads.g.doubleclick.net',
				'https://pagead2.googlesyndication.com',
				'https://securepubads.g.doubleclick.net',
				'https://tpc.googlesyndication.com',
				'https://www.googletagservices.com',
			],
			'googletagmanager' => [
				'https://www.googletagmanager.com',
			],
			'gstatic.com' => [
				'https://www.gstatic.com',
			],
			'hotjar.com' => [
				'https://script.hotjar.com',
			],
			'klaviyo.com/' => [
				'https://www.klaviyo.com',
				'https://a.klaviyo.com',
				'https://static.klaviyo.com',
				'https://static-tracking.klaviyo.com',
			],
			// OptinMonster.
			'omappapi.com' => [
				'https://a.omappapi.com',
				'https://api.omappapi.com',
			],
			'quantcast' => [
				'https://cmp.quantcast.com',
				'https://secure.quantserve.com',
			],
			// Jetpack.
			'stats.wp' => [
				'https://s.w.org',
				'https://stats.wp.com',
			],
			'taboola.com' => [
				'https://cdn.taboola.com',
			],
			'twitter.com' => [
				'https://platform.twitter.com',
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

		// Remove attributes.
		foreach ( $styles as $node ) {
			// Remove type.
			if ( $node->getAttribute( 'type' ) ) {
				$node->removeAttribute( 'type' );
				$node->normalize();
			}
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
	 * Minifies inline CSS.
	 *
	 * @param DOMDocument $dom
	 *
	 * @return void
	 */
	function handle_inline_styles( $dom ) {
		$styles = $dom->getElementsByTagName( 'style' );

		if ( ! $styles->length ) {
			return;
		}

		foreach ( $styles as $node ) {
			$inner = $node->textContent;

			if ( ! $inner ) {
				return;
			}

			$node->textContent = $this->minify_css( $node->textContent );
		}
	}

	/**
	 * Handles moving or removing scripts and styles.
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
			// $this->scripts = array_merge( [ $element ], $this->scripts ); // Add first.
			// $this->scripts = array_merge( $this->scripts, [ $element ] ); // Add last.
			$this->scripts[] = $element; // Add last.
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
	 * Minify inline CSS.
	 *
	 * @link https://gist.github.com/Rodrigo54/93169db48194d470188f
	 *
	 * @param string $input
	 *
	 * @return string
	 */
	function minify_css($input) {
		if ( '' === trim( $input ) ) {
			return $input;
		}

		return preg_replace(
			[
				// Remove comment(s)
				'#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')|\/\*(?!\!)(?>.*?\*\/)|^\s*|\s*$#s',
				// Remove unused white-space(s)
				'#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\'|\/\*(?>.*?\*\/))|\s*+;\s*+(})\s*+|\s*+([*$~^|]?+=|[{};,>~]|\s(?![0-9\.])|!important\b)\s*+|([[(:])\s++|\s++([])])|\s++(:)\s*+(?!(?>[^{}"\']++|"(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')*+{)|^\s++|\s++\z|(\s)\s+#si',
				// Replace `0(cm|em|ex|in|mm|pc|pt|px|vh|vw|%)` with `0`
				// '#(?<=[\s:])(0)(cm|em|ex|in|mm|pc|pt|px|vh|vw|%)#si',
				// Replace `:0 0 0 0` with `:0`
				'#:(0\s+0|0\s+0\s+0\s+0)(?=[;\}]|\!important)#i',
				// Replace `background-position:0` with `background-position:0 0`
				'#(background-position):0(?=[;\}])#si',
				// Replace `0.6` with `.6`, but only when preceded by `:`, `,`, `-` or a white-space
				'#(?<=[\s:,\-])0+\.(\d+)#s',
				// Minify string value
				'#(\/\*(?>.*?\*\/))|(?<!content\:)([\'"])([a-z_][a-z0-9\-_]*?)\2(?=[\s\{\}\];,])#si',
				'#(\/\*(?>.*?\*\/))|(\burl\()([\'"])([^\s]+?)\3(\))#si',
				// Minify HEX color code
				'#(?<=[\s:,\-]\#)([a-f0-6]+)\1([a-f0-6]+)\2([a-f0-6]+)\3#i',
				// Replace `(border|outline):none` with `(border|outline):0`
				'#(?<=[\{;])(border|outline):none(?=[;\}\!])#',
				// Remove empty selector(s)
				'#(\/\*(?>.*?\*\/))|(^|[\{\}])(?:[^\s\{\}]+)\{\}#s'
			],
			[
				'$1',
				'$1$2$3$4$5$6$7',
				// '$1',
				':0',
				'$1:0 0',
				'.$1',
				'$1$3',
				'$1$2$4$5',
				'$1$2$3',
				'$1:0',
				'$1$2'
			],
			$input
		);
	}

	/**
	 * Minify inline JS.
	 *
	 * @link https://gist.github.com/Rodrigo54/93169db48194d470188f
	 *
	 * @param string $input
	 *
	 * @return string
	 */
	function minify_js( $input ) {
		if ( '' === trim( $input ) ) {
			return $input;
		}

		return preg_replace(
			[
				// Remove comment(s).
				'#\s*("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')\s*|\s*\/\*(?!\!|@cc_on)(?>[\s\S]*?\*\/)\s*|\s*(?<![\:\=])\/\/.*(?=[\n\r]|$)|^\s*|\s*$#',
				// Remove white-space(s) outside the string and regex.
				'#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\'|\/\*(?>.*?\*\/)|\/(?!\/)[^\n\r]*?\/(?=[\s.,;]|[gimuy]|$))|\s*([!%&*\(\)\-=+\[\]\{\}|;:,.<>?\/])\s*#s',
				// Remove the last semicolon.
				'#;+\}#',
				// Minify object attribute(s) except JSON attribute(s). From `{'foo':'bar'}` to `{foo:'bar'}`.
				'#([\{,])([\'])(\d+|[a-z_][a-z0-9_]*)\2(?=\:)#i',
				// --ibid. From `foo['bar']` to `foo.bar`.
				'#([a-z0-9_\)\]])\[([\'"])([a-z_][a-z0-9_]*)\2\]#i'
			],
			[
				'$1',
				'$1$2',
				'}',
				'$1$3',
				'$1.$3'
			],
			$input
		);
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
	 * Makes sure gravatar is loaded securely.
	 *
	 * @param string $buffer The existing HTML buffer.
	 *
	 * @return string
	 */
	function do_gravatar( $buffer ) {
		// For Gravatar.
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
	 * Adds lazy loading to images.
	 *
	 * @param DOMNode $element
	 *
	 * @return void
	 */
	function do_lazy_images( $element ) {
		$images = $element->getElementsByTagName( 'img' );

		if ( ! $images->length ) {
			return;
		}

		$main = 'main' === $element->tagName;

		if ( $main ) {
			static $first = true;
		}

		foreach ( $images as $node ) {
			if ( $main ) {
				// Skip the first, likely above the fold.
				if ( $first ) {
					continue;
				}

				$first = false;
			}

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
	 * Adds lazy loading to iframes.
	 *
	 * @param DOMNode $node
	 *
	 * @return void
	 */
	function do_lazy_iframes( $element ) {
		$iframes = $element->getElementsByTagName( 'iframes' );

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
}

/**
 * Instantiates the class.
 * Add conditionals as needed.
 *
 * @return void
 */
add_action( 'after_setup_theme', function() {
	if ( is_user_logged_in() ) {
		return;
	}

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
