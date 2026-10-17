<?php
namespace MauticPlugin\WebAnyOnePluginManagerBundle\Service;
/** Validator for the closed JSON Schema subset shipped in catalogue/schema.json. */
final class Schema
{
    public static function check($value,array $rule,string $path='$'): void
    {
        if (isset($rule['$ref'])) { $all=json_decode(file_get_contents(__DIR__.'/../catalogue/schema.json'),true,64,JSON_THROW_ON_ERROR); $rule=$all['$defs'][substr($rule['$ref'],8)]; }
        $type=$rule['type'] ?? '';
        $ok=['object'=>is_object($value),'array'=>is_array($value),'string'=>is_string($value),'integer'=>is_int($value),'boolean'=>is_bool($value),'number'=>is_int($value)||is_float($value)];
        if ($type && !($ok[$type] ?? false)) { throw new \InvalidArgumentException('schema_type:'.$path); }
        if (isset($rule['enum']) && !in_array($value,$rule['enum'],true)) { throw new \InvalidArgumentException('schema_enum:'.$path); }
        if (is_string($value)) {
            if (strlen($value)>($rule['maxLength'] ?? 4096) || strlen($value)<($rule['minLength'] ?? 0) || (isset($rule['pattern']) && !preg_match('~'.$rule['pattern'].'~D',$value))) { throw new \InvalidArgumentException('schema_string:'.$path); }
        }
        if (is_int($value) || is_float($value)) { if ($value<($rule['minimum'] ?? -PHP_INT_MAX) || $value>($rule['maximum'] ?? PHP_INT_MAX)) { throw new \InvalidArgumentException('schema_number:'.$path); } }
        if (is_array($value)) {
            if (count($value)>($rule['maxItems'] ?? 1000) || count($value)<($rule['minItems'] ?? 0)) { throw new \InvalidArgumentException('schema_count:'.$path); }
            foreach ($value as $i=>$item) { self::check($item,$rule['items'],$path.'.'.$i); }
        }
        if (is_object($value)) {
            foreach ($rule['required'] ?? [] as $key) { if (!property_exists($value,$key)) { throw new \InvalidArgumentException('schema_required:'.$path.'.'.$key); } }
            foreach (get_object_vars($value) as $key=>$item) {
                if (isset($rule['properties'][$key])) { self::check($item,$rule['properties'][$key],$path.'.'.$key); }
                elseif (isset($rule['additionalProperties']) && is_array($rule['additionalProperties'])) { self::check($item,$rule['additionalProperties'],$path.'.'.$key); }
                else { throw new \InvalidArgumentException('schema_unknown:'.$path.'.'.$key); }
            }
        }
    }
}
