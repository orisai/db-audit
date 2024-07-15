<?php declare(strict_types = 1);

namespace Orisai\DbAudit\Schema;

final class LegacyCharset
{

	public static function isLegacy(string $charset): bool
	{
		return $charset !== 'utf8mb4' && $charset !== 'utf8mb3' && $charset !== 'utf8';
	}

	/**
	 * Single-byte legacy charsets (MAXLEN = 1, e.g. latin1, latin2, cp1250) cannot be told apart from
	 * double-encoded UTF-8 by byte width alone, so they stay behind the report-by-default
	 * getLegacyCharsetConversion() gate. Multibyte legacy charsets (MAXLEN > 1, e.g. gbk/big5/sjis)
	 * carry genuine multibyte data that transcodes losslessly to utf8mb4, so they are converted straight.
	 */
	public static function isSingleByte(string $charset, int $maxlen): bool
	{
		return self::isLegacy($charset) && $maxlen === 1;
	}

}
