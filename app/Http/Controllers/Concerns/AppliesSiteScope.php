<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait AppliesSiteScope
{
    protected function enforcedSiteIds(): ?array
    {
        return request()->attributes->get('enforced_site_ids');
    }

    protected function applySiteScope(Builder $query, string $column = 'site_id'): void
    {
        $ids = $this->enforcedSiteIds();

        if ($ids === null) {
            return;
        }

        $query->whereIn($column, $ids);
    }

    protected function isSiteEnforced(): bool
    {
        return $this->enforcedSiteIds() !== null;
    }

    protected function authorizeSiteAccess(?string $siteId, string $message = 'You do not have access to this resource.'): void
    {
        $ids = $this->enforcedSiteIds();

        if ($ids === null) {
            return;
        }

        abort_unless($siteId !== null && in_array($siteId, $ids, true), 403, $message);
    }

    protected function applyRequestedSiteFilter(
        Builder $query,
        mixed $siteFilter,
        string $column = 'site_id',
        ?string $nullFilterValue = null,
    ): void {
        $value = trim((string) ($siteFilter ?? ''));

        if ($value === '') {
            return;
        }

        $ids = $this->enforcedSiteIds();

        if ($nullFilterValue !== null && $value === $nullFilterValue) {
            abort_if($ids !== null, 403, 'You do not have access to this site scope.');
            $query->whereNull($column);

            return;
        }

        abort_if($ids !== null && ! in_array($value, $ids, true), 403, 'You do not have access to this site.');

        $query->where($column, $value);
    }

    protected function scopedSitesQuery(): Builder
    {
        $query = \App\Models\Site::query()->orderBy('name');

        $ids = $this->enforcedSiteIds();

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query;
    }
}
