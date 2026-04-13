<?php
/**
 * Project View API.
 *
 * Routes requests by endpoint name and returns example payloads as PHP
 * arrays. The front controller (public/index.php) is responsible for
 * JSON encoding and HTTP response handling.
 */

declare(strict_types=1);

namespace ProjectView;

final class Api
{
    /**
     * Dispatch a request to the matching endpoint method.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When the endpoint is unknown.
     */
    public function handle(string $endpoint): array
    {
        switch ($endpoint) {
            case 'kanban':
                return $this->kanban();
            case 'releases':
                return $this->releases();
            case 'time':
                return $this->time();
            default:
                throw new \InvalidArgumentException("unknown endpoint: {$endpoint}");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function kanban(): array
    {
        return [
            'columns' => [
                [
                    'id'        => 'todo',
                    'title'     => 'Todo',
                    'discarded' => false,
                    'cards'     => [
                        [
                            'title'         => 'Sample task A',
                            'category'      => 'Backend',
                            'milestone'     => 'v1.0',
                            'workStarted'   => null,
                            'workCompleted' => null,
                            'timeSpent'     => 0.0,
                        ],
                        [
                            'title'         => 'Sample task B',
                            'category'      => 'Design',
                            'milestone'     => 'v1.1',
                            'workStarted'   => null,
                            'workCompleted' => null,
                            'timeSpent'     => 0.0,
                        ],
                    ],
                ],
                [
                    'id'        => 'in-progress',
                    'title'     => 'In Progress',
                    'discarded' => false,
                    'cards'     => [
                        [
                            'title'         => 'Sample task C',
                            'category'      => 'Frontend',
                            'milestone'     => 'v1.0',
                            'workStarted'   => '2026-04-10',
                            'workCompleted' => null,
                            'timeSpent'     => 2.5,
                        ],
                    ],
                ],
                [
                    'id'        => 'done',
                    'title'     => 'Done',
                    'discarded' => false,
                    'cards'     => [
                        [
                            'title'         => 'Sample task D',
                            'category'      => 'DevOps',
                            'milestone'     => 'v0.9',
                            'workStarted'   => '2026-03-28',
                            'workCompleted' => '2026-04-02',
                            'timeSpent'     => 4.0,
                        ],
                        [
                            'title'         => 'Sample task E',
                            'category'      => 'Documentation',
                            'milestone'     => 'v0.9',
                            'workStarted'   => '2026-04-01',
                            'workCompleted' => '2026-04-05',
                            'timeSpent'     => 1.5,
                        ],
                    ],
                ],
                [
                    'id'        => 'discarded',
                    'title'     => 'Discarded',
                    'discarded' => true,
                    'cards'     => [
                        [
                            'title'         => 'Sample task F',
                            'category'      => 'Backend',
                            'milestone'     => 'v1.0',
                            'workStarted'   => '2026-04-03',
                            'workCompleted' => null,
                            'timeSpent'     => 1.0,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function releases(): array
    {
        return [
            'projects' => [
                [
                    'name'     => 'Atlas',
                    'releases' => [
                        ['version' => 'v2.4.0', 'date' => '2026-04-02', 'notes' => 'OAuth2 device flow, rotated signing keys.'],
                        ['version' => 'v2.3.1', 'date' => '2026-02-18', 'notes' => 'Fix leaking cursor on paginated search.'],
                        ['version' => 'v2.3.0', 'date' => '2026-01-05', 'notes' => 'Search API, composite indexes.'],
                    ],
                ],
                [
                    'name'     => 'Beacon',
                    'releases' => [
                        ['version' => 'v0.9.2', 'date' => '2026-03-27', 'notes' => 'Config reload without restart.'],
                        ['version' => 'v0.9.1', 'date' => '2026-02-09', 'notes' => 'Smaller static binary, fewer deps.'],
                    ],
                ],
                [
                    'name'     => 'Cartographer',
                    'releases' => [
                        ['version' => 'v1.12.0', 'date' => '2026-03-11', 'notes' => 'Vector tile export, MBTiles support.'],
                        ['version' => 'v1.11.0', 'date' => '2026-01-22', 'notes' => 'Offline tile caches.'],
                        ['version' => 'v1.10.3', 'date' => '2025-12-04', 'notes' => 'Layer ordering fix under WebGL 2.'],
                    ],
                ],
                [
                    'name'     => 'Drift',
                    'releases' => [
                        ['version' => 'v3.0.0', 'date' => '2026-03-05', 'notes' => 'Rewrote scheduler, backwards incompatible config.'],
                        ['version' => 'v2.8.4', 'date' => '2026-01-17', 'notes' => 'Last 2.x maintenance release.'],
                    ],
                ],
                [
                    'name'     => 'Ember',
                    'releases' => [
                        ['version' => 'v0.4.0', 'date' => '2026-02-28', 'notes' => 'First public preview, CLI ergonomics.'],
                    ],
                ],
                [
                    'name'     => 'Forge',
                    'releases' => [
                        ['version' => 'v5.7.1', 'date' => '2026-02-14', 'notes' => 'Patch: race in artifact upload.'],
                        ['version' => 'v5.7.0', 'date' => '2026-01-30', 'notes' => 'Matrix builds, reusable steps.'],
                        ['version' => 'v5.6.0', 'date' => '2025-11-12', 'notes' => 'SBOM generation on release tags.'],
                    ],
                ],
                [
                    'name'     => 'Grove',
                    'releases' => [
                        ['version' => 'v1.2.0', 'date' => '2026-01-26', 'notes' => 'Diff view in the dashboard.'],
                        ['version' => 'v1.1.0', 'date' => '2025-10-08', 'notes' => 'Webhooks for state transitions.'],
                    ],
                ],
                [
                    'name'     => 'Harbor',
                    'releases' => [
                        ['version' => 'v0.15.0', 'date' => '2025-12-20', 'notes' => 'Rootless container runtime.'],
                        ['version' => 'v0.14.2', 'date' => '2025-11-02', 'notes' => 'Fix registry auth refresh loop.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function time(): array
    {
        $categories = [
            'Development',
            'Media Production',
            'Third Party Contribution Management',
            'Testing',
            'Community Management',
            'Research',
        ];

        return [
            'categories' => $categories,
            'weeks'      => [
                [
                    'start'  => '2026-04-06',
                    'end'    => '2026-04-12',
                    'issues' => [
                        ['label' => '#42 Weekly time breakdown',    'hours' => [4.5, 0.0, 0.0, 1.0, 0.0, 1.5]],
                        ['label' => '#37 Kanban drag-and-drop',     'hours' => [3.0, 0.5, 0.0, 0.5, 0.0, 0.0]],
                        ['label' => '#29 Roadmap milestone import', 'hours' => [1.5, 0.0, 2.0, 0.0, 0.5, 1.0]],
                        ['label' => '#15 Onboarding screencast',    'hours' => [0.0, 3.5, 0.0, 0.0, 1.0, 0.0]],
                    ],
                ],
                [
                    'start'  => '2026-03-30',
                    'end'    => '2026-04-05',
                    'issues' => [
                        ['label' => '#37 Kanban drag-and-drop',     'hours' => [5.0, 0.0, 0.0, 2.0, 0.0, 0.5]],
                        ['label' => '#29 Roadmap milestone import', 'hours' => [3.0, 0.0, 1.5, 0.5, 0.0, 0.0]],
                        ['label' => '#22 Public API draft',         'hours' => [2.0, 0.0, 0.0, 0.0, 0.5, 2.5]],
                    ],
                ],
                [
                    'start'  => '2026-03-23',
                    'end'    => '2026-03-29',
                    'issues' => [
                        ['label' => '#22 Public API draft',                  'hours' => [4.0, 0.0, 0.0, 0.5, 0.0, 3.0]],
                        ['label' => '#18 Translation contributions review', 'hours' => [0.5, 0.0, 3.5, 0.0, 1.5, 0.0]],
                        ['label' => '#15 Onboarding screencast',            'hours' => [0.0, 2.5, 0.0, 0.0, 0.5, 0.5]],
                    ],
                ],
            ],
        ];
    }
}
