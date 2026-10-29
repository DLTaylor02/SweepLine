<?php
return [
    'twisty' => [
        'name' => 'Twisty Roads', 'description' => 'Sustained curves and continuous rural roads',
        // Sample counts are 30 m geometry samples, not counts of distinct corners.
        'eligibility' => ['curvature'=>0.12,'curve_density'=>7,'sustained_curve_m'=>120],
        'weights' => ['curvature' => 0.70, 'length' => 0.12, 'intersections' => 0.08, 'stops' => 0.06, 'classification' => 0.02, 'surface' => 0.02],
    ],
    'open' => [
        'name' => 'Open Highways', 'description' => 'Long, straight stretches for lawful cruising',
        'weights' => ['straightness' => 0.26, 'length' => 0.22, 'speed' => 0.15, 'intersections' => 0.15, 'stops' => 0.12, 'classification' => 0.07, 'surface' => 0.03],
    ],
];
