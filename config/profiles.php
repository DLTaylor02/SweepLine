<?php
return [
    'twisty' => [
        'name' => 'Twisty Roads', 'description' => 'Sustained curves and continuous rural roads',
        'weights' => ['curvature' => 0.40, 'length' => 0.18, 'intersections' => 0.16, 'stops' => 0.14, 'classification' => 0.07, 'surface' => 0.05],
    ],
    'open' => [
        'name' => 'Open Highways', 'description' => 'Long, straight stretches for lawful cruising',
        'weights' => ['straightness' => 0.26, 'length' => 0.22, 'speed' => 0.15, 'intersections' => 0.15, 'stops' => 0.12, 'classification' => 0.07, 'surface' => 0.03],
    ],
];
