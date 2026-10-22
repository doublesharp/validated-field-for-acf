<?php
namespace ACF_VF\App;
defined('ABSPATH') || exit;
/** Bounded lexical inspection only; calculation is supplied by Pro. */
final class ExpressionSyntax {
    public static function tokens(string $expression): array {
        if (strlen($expression)>4096 || trim($expression)==='') { throw new \RuntimeException('The formula must contain between 1 and 4096 characters.'); }
        $tokens=array(); $offset=0; $length=strlen($expression);
        while ($offset<$length) {
            if (preg_match('/\G\s+/A',$expression,$match,0,$offset)) { $offset+=strlen($match[0]); continue; }
            if (!preg_match('/\G(?:\d+(?:\.\d+)?|\.\d+|[a-zA-Z_][a-zA-Z0-9_]*|"[^"\\\\\r\n]*"|\'[^\'\\\\\r\n]*\'|[+*\/%,()\-])/A',$expression,$match,0,$offset)) { throw new \RuntimeException('The formula contains an unsupported token.'); }
            $tokens[]=$match[0]; $offset+=strlen($match[0]);
            if (count($tokens)>512) { throw new \RuntimeException('The formula has too many tokens.'); }
        }
        return $tokens;
    }

    /** Dependencies contain names only, never function identifiers or string literals. */
    public static function dependencies(string $expression) {
        try {
            $tokens=self::tokens($expression); $out=array();
            foreach ($tokens as $index=>$token) { if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$token) && ($tokens[$index+1] ?? null)!=='(') { $out[$token]=$token; } }
            return array_values($out);
        } catch (\Throwable $error) { return new \WP_Error('formula_error',$error->getMessage()); }
    }

}
