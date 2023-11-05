<?php

namespace Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators;

use Closure;
use Exception;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Support\Services\RelationshipJoiner;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\Operator;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;

class IsRelatedToOperator extends Operator
{
    protected string | Closure $titleAttribute;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    protected bool | Closure $isPreloaded = false;

    protected bool | Closure $isMultiple = false;

    protected bool | Closure $isNative = true;

    protected bool | Closure $isStatic = false;

    protected bool | Closure $isSearchable = false;

    protected int | Closure $optionsLimit = 50;

    protected bool | Closure | null $isSearchForcedCaseInsensitive = null;

    protected ?Closure $getOptionLabelUsing = null;

    protected ?Closure $getOptionLabelsUsing = null;

    protected ?Closure $getSearchResultsUsing = null;

    protected ?Closure $getOptionLabelFromRecordUsing = null;

    public function getName(): string
    {
        return 'isRelatedTo';
    }

    public function getLabel(): string
    {
        return $this->isInverse() ? 'Is not' : 'Is';
    }

    public function titleAttribute(string | Closure $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    /**
     * @return array<Component>
     */
    public function getFormSchema(): array
    {
        $constraint = $this->getConstraint();

        $this->getRelationship();

        $field = Select::make($constraint->isMultiple() ? 'values' : 'value')
            ->label($constraint->isMultiple() ? 'Values' : 'Value')
            ->multiple($this->isMultiple())
            ->searchable($this->isSearchable())
            ->preload($this->isPreloaded())
            ->native($this->isNative())
            ->optionsLimit($this->getOptionsLimit())
            ->required()
            ->relationship(
                $constraint->getRelationshipName(),
                $this->getTitleAttribute(),
                $this->modifyRelationshipQueryUsing,
            )
            ->forceSearchCaseInsensitive($this->isSearchForcedCaseInsensitive());

        if ($this->getOptionLabelUsing) {
            $field->getOptionLabelUsing($this->getOptionLabelUsing);
        }

        if ($this->getOptionLabelsUsing) {
            $field->getOptionLabelsUsing($this->getOptionLabelsUsing);
        }

        if ($this->getOptionLabelFromRecordUsing) {
            $field->getOptionLabelFromRecordUsing($this->getOptionLabelFromRecordUsing);
        }

        if ($this->getSearchResultsUsing) {
            $field->getSearchResultsUsing($this->getSearchResultsUsing);
        }

        return [$field];
    }

    public function getSummary(): string
    {
        $constraint = $this->getConstraint();

        $values = Arr::wrap($this->getSettings()[$constraint->isMultiple() ? 'values' : 'value']);

        $relationshipQuery = $this->getRelationshipQuery();

        $values = $relationshipQuery
            ->when(
                $this->getRelationship() instanceof \Znck\Eloquent\Relations\BelongsToThrough,
                fn (Builder $query) => $query->distinct(),
            )
            ->whereKey($values)
            ->pluck($relationshipQuery->qualifyColumn($this->getTitleAttribute()))
            ->join(glue: ', ', finalGlue: ' or ');

        return $this->isInverse() ? "{$constraint->getAttributeLabel()} is not \"{$values}\"" : "{$constraint->getAttributeLabel()} is \"{$values}\"";
    }

    public function modifyRelationshipQueryUsing(?Closure $modifyQueryUsing = null): static
    {
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;

        return $this;
    }

    public function getOptionLabelUsing(?Closure $callback): static
    {
        $this->getOptionLabelUsing = $callback;

        return $this;
    }

    public function getOptionLabelsUsing(?Closure $callback): static
    {
        $this->getOptionLabelsUsing = $callback;

        return $this;
    }

    public function getSearchResultsUsing(?Closure $callback): static
    {
        $this->getSearchResultsUsing = $callback;

        return $this;
    }

    public function apply(Builder $query, string $qualifiedColumn): Builder
    {
        $constraint = $this->getConstraint();

        $value = $this->getSettings()[$constraint->isMultiple() ? 'values' : 'value'];

        return $query->{$this->isInverse() ? 'whereDoesntHave' : 'whereHas'}(
            $constraint->getRelationshipName(),
            fn (Builder $query) => $query->whereKey($value),
        );
    }

    public function getConstraint(): ?RelationshipConstraint
    {
        $constraint = parent::getConstraint();

        if (! ($constraint instanceof RelationshipConstraint)) {
            throw new Exception('Is operator can only be used with relationship constraints.');
        }

        return $constraint;
    }

    public function forceSearchCaseInsensitive(bool | Closure | null $condition = true): static
    {
        $this->isSearchForcedCaseInsensitive = $condition;

        return $this;
    }

    public function isSearchForcedCaseInsensitive(): ?bool
    {
        return $this->evaluate($this->isSearchForcedCaseInsensitive);
    }

    public function getModifyRelationshipQueryUsing(): ?Closure
    {
        return $this->modifyRelationshipQueryUsing;
    }

    public function getRelationship(): Relation | Builder
    {
        $constraint = $this->getConstraint();

        $record = app($constraint->getFilter()->getTable()->getModel());

        $relationship = null;

        foreach (explode('.', $constraint->getRelationshipName()) as $nestedRelationshipName) {
            if (! $record->isRelation($nestedRelationshipName)) {
                $relationship = null;

                break;
            }

            $relationship = $record->{$nestedRelationshipName}();
            $record = $relationship->getRelated();
        }

        return $relationship;
    }

    public function getRelationshipQuery(): ?Builder
    {
        $relationship = Relation::noConstraints(fn () => $this->getRelationship());

        $relationshipQuery = app(RelationshipJoiner::class)->prepareQueryForNoConstraints($relationship);

        if ($this->getModifyRelationshipQueryUsing()) {
            $relationshipQuery = $this->evaluate($this->modifyRelationshipQueryUsing, [
                'query' => $relationshipQuery,
            ]) ?? $relationshipQuery;
        }

        if (empty($relationshipQuery->getQuery()->orders)) {
            $relationshipQuery->orderBy($relationshipQuery->qualifyColumn($this->getTitleAttribute()));
        }

        return $relationshipQuery;
    }

    public function getTitleAttribute(): string
    {
        return $this->evaluate($this->titleAttribute) ?? throw new Exception("The [titleAttribute()] is required for the [IsOperator] on the [{$this->getConstraint()->getName()}] constraint.");
    }

    public function multiple(bool | Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function searchable(bool | Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->isSearchable);
    }

    public function optionsLimit(int | Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    public function getOptionsLimit(): int
    {
        return $this->evaluate($this->optionsLimit);
    }

    public function native(bool | Closure $condition = true): static
    {
        $this->isNative = $condition;

        return $this;
    }

    public function isNative(): bool
    {
        return (bool) $this->evaluate($this->isNative);
    }

    public function getOptionLabelFromRecordUsing(?Closure $callback): static
    {
        $this->getOptionLabelFromRecordUsing = $callback;

        return $this;
    }

    public function preload(bool | Closure $condition = true): static
    {
        $this->isPreloaded = $condition;

        return $this;
    }

    public function isPreloaded(): bool
    {
        return (bool) $this->evaluate($this->isPreloaded);
    }
}
