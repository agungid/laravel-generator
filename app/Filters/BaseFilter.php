<?php

namespace Atnic\LaravelGenerator\Filters;

use Arados\Filters\Filter;
use Illuminate\Http\Request;

/**
 * Base Filters
 */
class BaseFilter extends Filter
{
    /** @var array Array Searchable */
    protected $searchables = [];

    /** @var array Array Sortable */
    protected $sortables = [];

    /** @var string|null Default sort */
    protected $default_sort = null;

    /**
     * Filter constructor.
     *
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = clone $request;
        if (!$this->request->exists('sort') && $this->default_sort) {
            $this->request->merge([ 'sort' => $this->default_sort ]);
        }
    }

    /**
     * Search
     * @param  mixed $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function search($value)
    {
        $validator = validator([ 'value' => $value ], [ 'value' => 'required|string' ]);
        return $this->builder->when(!$validator->fails(), function ($query) use($value) {
            $query->where(function ($query) use($value) {
                foreach ($this->searchables as $key => $searchable) {
                    if (is_array($searchable)) {
                        $query->orWhereHas($key, function ($query) use($searchable, $value) {
                            $query->where(function ($query) use($searchable, $value) {
                                foreach ($searchable as $key => $searchable_child) {
                                    $query->orWhere($query->qualifyColumn($searchable_child), 'like', '%'.str_replace(' ', '%', $value).'%');
                                }
                            });
                        });
                    } else {
                        $query->orWhere($query->qualifyColumn($searchable), 'like', '%'.str_replace(' ', '%', $value).'%');
                    }
                }
            });
        });
    }

    /**
     * Sort
     * @param  mixed $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function sort($value)
    {
        $sorted_columns = $value ? explode('|', $value) : [];
        $sorts = [];
        foreach ($sorted_columns as $key => $sorted_column) {
            $sort = $sorted_column ? explode(',', $sorted_column) : [];
            if (!is_array($sort) || !in_array($sort[0], $this->sortables)) continue;
            array_push($sorts, [
                'column' => $sort[0],
                'dir' => isset($sort[1]) ? $sort[1] : 'asc'
            ]);
        }
        $validator = validator([ 'sorts' => $sorts ], [
            'sorts.*.column' => 'required|in:'.implode(',', $this->sortables),
            'sorts.*.dir' => 'in:asc,desc'
        ]);
        return $this->builder->when(!$validator->fails(), function ($query) use($sorts) {
            $query->select($query->qualifyColumn('*'));
            foreach ($sorts as $key => $sort) {
                if (str_contains($sort['column'], '.')) {
                    $join = explode('.', $sort['column']);
                    $relation = $query->getModel()->{$join[0]}();
                    if (in_array(class_basename($relation), [ 'BelongsTo', 'MorphTo' ])) {
                        $query->leftJoin($relation->getRelated()->getTable(), $relation->getQualifiedForeignKey(), '=', $relation->getQualifiedOwnerKeyName());
                        $query->orderBy($relation->getRelated()->getTable().'.'.$join[1], $sort['dir']);
                        $query->addSelect($relation->getRelated()->getTable().'.'.$join[1].' as '.$join[0].'_'.$join[1]);
                    }
                } else {
                    $query->orderBy($sort['column'], $sort['dir']);
                }
            }
        });
    }
}
