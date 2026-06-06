<?php

declare(strict_types=1);

namespace Strux\Component\Database\Schema\Types;

enum Field: string
{
    // Integers
    case int = 'int';                   // Standard Integer
    case intUnsigned = 'intUnsigned';   // Standard Integer Unsigned
    case integer = 'integer';           // Alias for int
    case integerUnsigned = 'integerUnsigned'; // Alias for intUnsigned

    case tinyInteger = 'tinyInteger';
    case tinyIntegerUnsigned = 'tinyIntegerUnsigned';

    case smallInteger = 'smallInteger';
    case smallIntegerUnsigned = 'smallIntegerUnsigned';

    case mediumInteger = 'mediumInteger';
    case mediumIntegerUnsigned = 'mediumIntegerUnsigned';

    case bigInteger = 'bigInteger';
    case bigIntegerUnsigned = 'bigIntegerUnsigned';

    // Strings & Text
    case string = 'string';             // VARCHAR
    case char = 'char';                 // CHAR
    case text = 'text';                 // TEXT
    case mediumText = 'mediumText';
    case longText = 'longText';

    // Booleans
    case boolean = 'boolean';

    // Decimals & Floats
    case decimal = 'decimal';
    case double = 'double';
    case float = 'float';

    // Dates & Times
    case date = 'date';
    case dateTime = 'dateTime';
    case time = 'time';
    case timestamp = 'timestamp';
    case year = 'year';

    // Special
    case json = 'json';
    case enum = 'enum';
    case binary = 'binary';             // BLOB
    case uuid = 'uuid';                 // CHAR(36)
    case ulid = 'ulid';                 // CHAR(26)

    public static function decimal(int $precision = 10, int $scale = 2): string
    {
        return "decimal($precision, $scale)";
    }
}