<?php
/**
 * Generates languages/teepay-for-woocommerce.pot without WP-CLI.
 *
 * Usage: php bin/make-pot.php
 * (`wp i18n make-pot . languages/teepay-for-woocommerce.pot` produces an equivalent file.)
 *
 * @package TeePay\WooCommerce
 */

// phpcs:ignoreFile

$root    = dirname( __DIR__ );
$domain  = 'teepay-for-woocommerce';
$simple  = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );
$entries = array();

$add = static function ( $msgid, $reference, $comment ) use ( &$entries ) {
	if ( ! isset( $entries[ $msgid ] ) ) {
		$entries[ $msgid ] = array( 'refs' => array(), 'comments' => array() );
	}
	$entries[ $msgid ]['refs'][] = $reference;
	if ( '' !== $comment && ! in_array( $comment, $entries[ $msgid ]['comments'], true ) ) {
		$entries[ $msgid ]['comments'][] = $comment;
	}
};

// Plugin header strings.
$main = file_get_contents( $root . '/teepay-for-woocommerce.php' );
foreach ( array( 'Plugin Name', 'Description', 'Author' ) as $header ) {
	if ( preg_match( '/^\s*\*\s*' . preg_quote( $header, '/' ) . ':\s*(.+)$/m', $main, $match ) ) {
		$add( trim( $match[1] ), 'teepay-for-woocommerce.php', $header . ' of the plugin' );
	}
}

$files = array( $root . '/teepay-for-woocommerce.php' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes' ) ) as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );

foreach ( $files as $path ) {
	$relative = str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) );
	$tokens   = token_get_all( file_get_contents( $path ) );
	$comment  = '';
	$count    = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];
		if ( is_array( $token ) && T_COMMENT === $token[0] && false !== stripos( $token[1], 'translators:' ) ) {
			$comment = trim( preg_replace( '#^/\*\s*|\s*\*/$|^//\s*#', '', $token[1] ) );
			continue;
		}
		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $simple, true ) ) {
			continue;
		}
		$line = $token[2];
		$j    = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			$j++;
		}
		if ( '(' !== $tokens[ $j ] ) {
			continue;
		}
		$args    = array();
		$current = '';
		$depth   = 0;
		for ( $j++; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '(' === $t ) {
				$depth++;
			} elseif ( ')' === $t ) {
				if ( 0 === $depth ) {
					$args[] = $current;
					break;
				}
				$depth--;
			} elseif ( ',' === $t && 0 === $depth ) {
				$args[]  = $current;
				$current = '';
				continue;
			}
			if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && 0 === $depth ) {
				$current .= $t[1];
			} elseif ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT ), true ) ) {
				continue;
			} else {
				$current .= '#';
			}
		}
		if ( 2 > count( $args ) || "'" . $domain . "'" !== trim( $args[1] ) ) {
			fwrite( STDERR, "Skipping non-literal or wrong-domain call in $relative:$line\n" );
			continue;
		}
		$literal = trim( $args[0] );
		if ( ! preg_match( "/^'(.*)'$/s", $literal, $m ) ) {
			fwrite( STDERR, "Skipping non-literal string in $relative:$line\n" );
			continue;
		}
		$add( str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $m[1] ), $relative . ':' . $line, $comment );
		$comment = '';
	}
}

$escape = static function ( $text ) {
	return str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\n', '\t' ), $text );
};

$out  = "# Copyright (C) " . gmdate( 'Y' ) . " TeePay\n";
$out .= "# This file is distributed under the GPL-2.0-or-later.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"Project-Id-Version: TeePay for WooCommerce 1.0.0\\n\"\n";
$out .= "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/teepay-for-woocommerce\\n\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"POT-Creation-Date: " . gmdate( 'Y-m-d\TH:i:s+00:00' ) . "\\n\"\n";
$out .= "\"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n\"\n";
$out .= "\"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n\"\n";
$out .= "\"Language-Team: LANGUAGE <LL@li.org>\\n\"\n";
$out .= "\"X-Domain: " . $domain . "\\n\"\n";

foreach ( $entries as $msgid => $entry ) {
	$out .= "\n";
	foreach ( $entry['comments'] as $comment ) {
		$out .= '#. ' . $comment . "\n";
	}
	$out .= '#: ' . implode( ' ', array_unique( $entry['refs'] ) ) . "\n";
	if ( false !== strpos( $msgid, '%' ) ) {
		$out .= "#, php-format\n";
	}
	$out .= 'msgid "' . $escape( $msgid ) . "\"\nmsgstr \"\"\n";
}

file_put_contents( $root . '/languages/' . $domain . '.pot', $out );
echo count( $entries ) . " strings written to languages/$domain.pot\n";
