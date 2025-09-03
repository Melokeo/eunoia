<?php
// /var/lib/euno/graph-memory/Schema.php
declare(strict_types=1);

/**
 * Declarative schema for node/edge kinds and merge rules (placeholders).
 * Pure PHP arrays to avoid JSON surfaces.
 */
final class Schema
{
    /** @var string[] */
    private array $nodeTypes = [
        'Person', 'Project', 'Task', 'Preference', 'Artifact', 'Time', 'Quantity', 'Other'
    ];

    /** @var string[] */
    private array $edgeTypes = [
        'assigned_to', 'prefers', 'depends_on', 'member_of', 'related_to', 'owns', 'scheduled_for'
    ];

    /** Minimal attribute allow-list per node type (extensible). */
    private array $nodeAttrWhitelist = [
        'Person'    => ['email','role','org'],
        'Project'   => ['status','deadline'],
        'Task'      => ['status','deadline','priority'],
        'Preference'=> ['value','scale'],
        'Artifact'  => ['url','path','format'],
        'Time'      => ['iso','range'],
        'Quantity'  => ['value','unit'],
        'Other'     => []
    ];

    /** Merge rules placeholder per node type. */
    private array $mergeRules = [
        'Person'  => ['key' => ['title'], 'alias' => true],
        'Project' => ['key' => ['title'], 'alias' => true],
        'Task'    => ['key' => ['title'], 'alias' => true],
        'Default' => ['key' => ['title'], 'alias' => true],
    ];

    public function validNodeType(string $type): bool
    {
        return in_array($type, $this->nodeTypes, true);
    }

    public function validEdgeType(string $type): bool
    {
        return in_array($type, $this->edgeTypes, true);
    }

    /** Return allowed attribute keys for a node type. */
    public function attrsFor(string $nodeType): array
    {
        return $this->nodeAttrWhitelist[$nodeType] ?? [];
    }

    /** Return merge rule for a node type. */
    public function mergeRule(string $nodeType): array
    {
        return $this->mergeRules[$nodeType] ?? $this->mergeRules['Default'];
    }

    /** Node types list. */
    public function nodeTypes(): array { return $this->nodeTypes; }

    /** Edge types list. */
    public function edgeTypes(): array { return $this->edgeTypes; }
}
