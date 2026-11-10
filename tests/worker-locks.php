<?php
    check(\SweepLine\SearchJobs::WORKER_LOCK!==\SweepLine\BeamJobs::ENQUEUE_LOCK&&\SweepLine\SearchJobs::WORKER_LOCK!==\SweepLine\BeamJobs::MAINTENANCE_LOCK,'Search service cannot monopolize BeamNG admission or maintenance locks');
