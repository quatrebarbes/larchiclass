# laravel-archi

A Laravel package that analyzes your PHP classes and generates a **PlantUML class diagram** (`.puml`).

## Installation

```bash
composer require quatrebarbes/larchiclass --dev
```

Laravel auto-discovers the service provider. No manual registration needed.

---

## larchi:class

```bash
php artisan larchi:class
```

Generates `larchi-class.puml` at the root of your project.

### Custom namespace

```bash
php artisan larchi:class --namespace="App\Domain\Billing"
```

### Custom output file

```bash
php artisan larchi:class --output="docs/diagram.puml"
```

### Both options combined

```bash
php artisan larchi:class --namespace="App\Domain\Marketing" --output="docs/marketing.puml"
```

---

## larchi:mdel

```bash
php artisan larchi:model
```

Generates `larchi-model.puml` at the root of your project.

### Custom namespace

```bash
php artisan larchi:model --namespace="App\Models\SubDomain"
```

### Custom output file

```bash
php artisan larchi:model --output="docs/diagram.puml"
```

### Both options combined

```bash
php artisan larchi:model --namespace="App\Models\SubDomain" --output="docs/sub-domain.puml"
```

---
