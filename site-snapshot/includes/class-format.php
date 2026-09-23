<?php
/**
 * Plain-text number and size formatting.
 *
 * number_format_i18n() and size_format() return HTML entities in some locales
 * (cs_CZ uses "&nbsp;" as the thousands separator). That is fine inside
 * esc_html() output but wrong in JSON, JS textContent, SITE-INFO.txt and the
 * audit log. Only the formatted number is decoded – never user data.
 *
 * @package SiteSnapshot
 */

namespace SiteSnapshot;

defined( 'ABSPATH' ) || exit;

final class Format {

	public static function number( $number ) {
		return html_entity_decode( number_format_i18n( $number ), ENT_QUOTES, 'UTF-8' );
	}

	public static function size( $bytes, $decimals = 1 ) {
		return html_entity_decode( (string) size_format( $bytes, $decimals ), ENT_QUOTES, 'UTF-8' );
	}
}
