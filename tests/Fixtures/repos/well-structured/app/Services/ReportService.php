<?php

namespace App\Services;

use App\Models\Report;
use App\Support\AuditTrail;

class ReportService
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function create(array $attributes): Report
    {
        $model = Report::query()->create($attributes);
        $this->audit->record('report.created', ['id' => $model->id]);

        return $model;
    }

    public function limit(): int
    {
        return (int) config('kennel.report_limit', 10);
    }
}
