# LarchiClass 😎

Some architecture-related commands for Laravel developers

**PHP 8.1+** · **Laravel 10 / 11** · install as `--dev`

Generate PlantUML class diagrams from your Laravel namespaces.

LarchiClass inspects your PHP classes produces a `.puml` file ready to render with PlantUML. The `larchi:class` command covers all PHP types (classes, interfaces, traits, enums), while `larchi:model` enriches the diagram with Eloquent properties and model relationships.

## Installation

```bash
composer require quatrebarbes/larchiclass --dev
```

## Commands

| Command        | Description                                                                                                            |
|----------------|------------------------------------------------------------------------------------------------------------------------|
| `larchi:class` | General-purpose diagram — classes, interfaces, traits, enums. Dependencies resolved from type hints.                   |
| `larchi:model` | Eloquent diagram — `$fillable`, `$casts`, `$hidden` properties and relationships (`hasMany`, `belongsTo`, `morphTo`…). |

### Available options

| Option           | Description                                                                     | Default                                   |
|------------------|---------------------------------------------------------------------------------|-------------------------------------------|
| `--namespace`    | Namespace to analyze (basic string ou regex)                                    | `App` / `App\Models`                      |
| `--output`       | Output file path                                                                | `larchi-class.puml` / `larchi-model.puml` |
| `--with-related` | Include referenced classes (parents, interfaces, traits, dependencies) as stubs | disabled                                  |
| `--with-vendors` | Include classes from `/vendor/`                                                 | disabled                                  |


### Examples of using a general class diagram

```bash
# Default namespace (App), output: larchi-class.puml
php artisan larchi:class

# Custom namespace
php artisan larchi:class --namespace="App\Domain\Billing"

# Custom output file
php artisan larchi:class --output="docs/billing.puml"

# Include parents and dependencies outside the namespace
php artisan larchi:class --namespace="App\Http\Controllers" --with-related

# Include everything, vendor classes included
php artisan larchi:class --with-related --with-vendors
```

### Example of using an Eloquent model diagram

```bash
# Default namespace (App\Models), output: larchi-model.puml
php artisan larchi:model

# Specific subdomain
php artisan larchi:model --namespace="App\Models\Billing" --output="docs/billing-models.puml"

# Include Eloquent parent classes
php artisan larchi:model --with-related --with-vendors
```

> The `.puml` file can be rendered with [PlantUML](https://plantuml.com/), the VS Code PlantUML extension, or any compatible tool. The diagram is left-to-right by default.

## What the diagram includes

### larchi:class

- All classes, interfaces, traits, and enums in the target namespace
- Properties with type, visibility (`+` `#` `-`), and `{static}` modifier
- Methods with visibility, `{abstract}`, and `{static}` modifiers
- Inheritance arrows (`<|--`), implementation arrows (`<|..`), trait usage (`<..`), and dependency arrows (`..>`)
- Stubs for classes referenced outside the scope (with `--with-related`)

### larchi:model (everything above, plus…)

- Properties sourced from `$fillable`, `$hidden`, `$casts`, `$dates`, and `$appends`
- Computed visibility: a field present in both `$fillable` and `$hidden` is rendered as `private`
- Types resolved from `$casts`: `decimal:2` → `decimal`, `AsCollection::class` → `AsCollection`
- Eloquent relationships: `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasManyThrough`, `hasOneThrough`, `morphOne`, `morphMany`, `morphTo`, `morphToMany`, `morphedByMany`
- Automatic cardinality (`"1" -- "*"`, `"*" -- "*"`, etc.)
- Reciprocal pairs merged into a single arrow annotated with both method names
- Stereotypes `<<model>>`, `<<vendor>>`, `<<trait>>`
