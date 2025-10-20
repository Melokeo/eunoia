<?php
declare(strict_types=1);

/**
 * API Response Parser
 * Extracts text content from various AI API response formats
 */

/**
 * Extract text content from API response
 * @param array $resp Raw API response array
 * @param string $apiType API type: 'claude', 'open-ai-new', 'open-ai-old'
 * @return string Sanitized text content
 */
function extract_text(array $resp, string $apiType): string {
    $raw = match($apiType) {
        'claude'      => extract_claude($resp),
        'open-ai-new' => extract_openai_new($resp),
        'open-ai-old' => extract_openai_old($resp),
        default       => ''
    };
    
    return sanitize_punct($raw);
}

function extract_claude(array $resp): string {
    foreach ($resp['content'] ?? [] as $block) {
        if ($block['type'] === 'text') {
            return $block['text'];
        }
    }
    return '';
}

function extract_openai_new(array $resp): string {
    // Direct text
    if (isset($resp['output_text']) && is_string($resp['output_text'])) {
        return $resp['output_text'];
    }
    
    // Array of outputs
    if (isset($resp['output']) && is_array($resp['output'])) {
        $buf = [];
        foreach ($resp['output'] as $part) {
            if (isset($part['content'][0]['text'])) {
                $buf[] = $part['content'][0]['text'];
            } elseif (isset($part['text'])) {
                $buf[] = $part['text'];
            }
        }
        return implode("\n", $buf);
    }
    
    // Fallback: return raw JSON
    return json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function extract_openai_old(array $resp): string {
    $msg = $resp['choices'][0]['message'] ?? [];
    
    if (!empty($msg['content'])) {
        return $msg['content'];
    }
    
    if (!empty($msg['reasoning_content'])) {
        return (string)$msg['reasoning_content'];
    }
    
    return '';
}

/**
 * Sanitize punctuation in text
 * Converts em/en dashes and double hyphens to commas
 */
function sanitize_punct(string $s): string {
    // Replace dashes with commas when surrounded by spaces
    $s = preg_replace('/\h(?:—|–|--|-)\h/u', ', ', $s);
    // Collapse duplicate commas
    $s = preg_replace('/\s*,\s*,+/', ', ', $s);
    // Collapse spaces/tabs
    $s = preg_replace('/[ \t]{2,}/', ' ', $s);
    // Normalize newlines: single → double, 3+ → double
    // $s = preg_replace('/(?<!\n)\n(?!\n)/', "\n\n", $s);  // single to double
    $s = preg_replace_callback(
        '/~~~.*?~~~|(?<!\n)\n(?!\n)/s', 
        function($m){ 
            return str_starts_with($m[0], '~~~') ? $m[0] : "\n\n"; 
        }, 
        $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);           // 3+ to double
    
    return trim($s);
}

function get_tool_call(array $data) {
    return is_array($data['content']['tool_calls'] ?? null) ? 
        $data['content']['tool_calls'] : 
        [];
}