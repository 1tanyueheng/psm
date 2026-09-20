#!/usr/bin/env python3
"""
Static consistency check for the PSM backend seeders.

There is no PHP runtime in this environment, so this script does the checks
that matter most and that a linter could not do anyway: verifying that every
column a seeder writes actually exists in the corresponding migration, and
that every service/model method a seeder calls is really defined.

Run:  python tools/check_seeders.py
Exit: 0 when consistent, 1 when problems are found.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MIGRATIONS = ROOT / "database" / "migrations"
SEEDERS = ROOT / "database" / "seeders"
MODELS = ROOT / "app" / "Models"
SERVICES = ROOT / "app" / "Services"

problems: list[str] = []
notes: list[str] = []


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def schema_columns() -> dict[str, set[str]]:
    """Map table name -> set of column names, from every migration."""
    tables: dict[str, set[str]] = {}

    for path in MIGRATIONS.glob("*.php"):
        src = read(path)
        # Split on Schema::create so each block belongs to one table
        for block in re.split(r"Schema::create\(", src)[1:]:
            m = re.match(r"\s*'([a-z_]+)'", block)
            if not m:
                continue
            table = m.group(1)
            cols: set[str] = set()

            # $table->string('name', ...) / ->foreignId('x') / ->enum('y', ...)
            # The type list must cover every column-declaring method actually
            # used in the migrations, or columns are missed and reported as
            # phantom mismatches.
            for cm in re.finditer(
                r"\$table->(?:"
                r"id|increments|bigIncrements|"
                r"string|char|text|mediumText|longText|"
                r"enum|set|"
                r"boolean|"
                r"integer|tinyInteger|smallInteger|mediumInteger|bigInteger|"
                r"unsignedInteger|unsignedTinyInteger|unsignedSmallInteger|"
                r"unsignedMediumInteger|unsignedBigInteger|"
                r"decimal|float|double|"
                r"date|dateTime|timestamp|time|year|"
                r"json|jsonb|"
                r"binary|uuid|ulid|foreignUlid|foreignUuid|foreignId|"
                r"ipAddress|macAddress|userAgent|rememberToken|softDeletes"
                r")\s*\(\s*'([a-z_0-9]+)'",
                block,
            ):
                cols.add(cm.group(1))

            # ->constrained()/->nullable() wrappers add no column
            if "$table->id()" in block:
                cols.add("id")
            if "$table->timestamps()" in block:
                cols |= {"created_at", "updated_at"}
            if "$table->softDeletes()" in block:
                cols.add("deleted_at")
            if "$table->rememberToken()" in block:
                cols.add("remember_token")

            # ->json('x') chained on foreignId is caught above; also catch bare calls
            for cm in re.finditer(r"->(?:json|text|string)\s*\(\s*'([a-z_0-9]+)'\s*\)", block):
                cols.add(cm.group(1))

            tables.setdefault(table, set()).update(cols)

    return tables


def model_for_table(name: str) -> str:
    """Best-effort mapping of a table name to its model class file."""
    singular = name.rstrip("s")
    candidates = [
        "".join(p.capitalize() for p in singular.split("_")),
        "".join(p.capitalize() for p in name.split("_")),
    ]
    # A few models do not follow naive singularisation
    overrides = {
        "supervisor_expertise": None,
        "cache_locks": None,
        "job_batches": None,
        "failed_jobs": None,
        # Latin plurals: the model is Criterion, the table is criteria
        "rubric_criteria": "RubricCriterion",
        "submission_events": "SubmissionEvent",
        "leaderboard_settings": "LeaderboardSetting",
    }
    if name in overrides:
        return overrides[name] or ""

    for candidate in candidates:
        if (MODELS / f"{candidate}.php").exists():
            return candidate
    return ""


def declared_service_methods() -> dict[str, set[str]]:
    """service class name -> public method names."""
    out: dict[str, set[str]] = {}
    for path in SERVICES.rglob("*.php"):
        src = read(path)
        name = path.stem
        out[name] = set(re.findall(r"public function ([a-zA-Z_][a-zA-Z_0-9]*)", src))
    return out


def model_relations() -> dict[str, set[str]]:
    """model class name -> relation/method names."""
    out: dict[str, set[str]] = {}
    for path in MODELS.glob("*.php"):
        src = read(path)
        methods = set(re.findall(r"public function ([a-zA-Z_][a-zA-Z_0-9]*)", src))
        methods |= set(re.findall(r"public static function ([a-zA-Z_][a-zA-Z_0-9]*)", src))
        out[path.stem] = methods
    return out


def check_fillable_against_schema(tables: dict[str, set[str]]) -> None:
    """Every fillable key on a model must exist as a column in its table."""
    # Build a reverse map: model file -> table name(s) it declares
    table_names = set(tables)

    for path in MODELS.glob("*.php"):
        src = read(path)
        model = path.stem

        fm = re.search(r"protected \$fillable = \[(.*?)\];", src, re.S)
        if not fm:
            continue

        fillable = re.findall(r"'([a-z_0-9]+)'", fm.group(1))
        if not fillable:
            continue

        # Find the table this model uses: explicit $table, else snake plural
        tm = re.search(r"protected \$table = '([a-z_]+)'", src)
        if tm:
            table = tm.group(1)
        else:
            # Reverse of model_for_table()'s overrides: a few models' tables do
            # not follow from naive pluralisation.
            model_overrides = {
                "RubricCriterion": "rubric_criteria",
                "SubmissionEvent": "submission_events",
                "LeaderboardSetting": "leaderboard_settings",
            }
            snake = re.sub(r"(?<!^)(?=[A-Z])", "_", model).lower()
            table = (
                model_overrides.get(model)
                or (snake if snake in table_names else snake + "s")
            )
            if table not in table_names:
                # try common irregulars
                for alt in (snake + "es", snake[:-1] + "ies", snake):
                    if alt in table_names:
                        table = alt
                        break

        if table not in tables:
            notes.append(f"{model}: no migration table matched (guessed '{table}') — skipped")
            continue

        # `id` is always present (declared via ->id()) but the regex above only
        # catches the positional form; treat it as valid on every table.
        missing = [c for c in fillable if c not in tables[table] and c != "id"]
        if missing:
            problems.append(
                f"{model} ($fillable) declares columns absent from table '{table}': "
                + ", ".join(sorted(missing))
            )


def check_seeder_writes(tables: dict[str, set[str]]) -> None:
    """
    For every Model::updateOrCreate / ::create call in a seeder, verify the
    keys written exist on that model's table.
    """
    for path in SEEDERS.glob("*.php"):
        src = read(path)
        seeder = path.name

        for call in re.finditer(
            r"([A-Z][A-Za-z]+)::(?:updateOrCreate|create|firstOrCreate)\s*\((.*?)\n\s*\);",
            src,
            re.S,
        ):
            model = call.group(1)
            body = call.group(2)

            if not (MODELS / f"{model}.php").exists():
                continue

            # Only inspect the association array (the outer [...] block)
            model_src = read(MODELS / f"{model}.php")
            fm = re.search(r"protected \$fillable = \[(.*?)\];", model_src, re.S)
            fillable = set(re.findall(r"'([a-z_0-9]+)'", fm.group(1))) if fm else set()

            if not fillable:
                continue

            # Strip nested array literals before looking for column keys.
            # `'metadata' => ['progress_profile' => ...]` is one column whose
            # *value* happens to be an array — its inner keys are not columns.
            flat = re.sub(r"=>\s*\[.*?\]", "=> []", body, flags=re.S)

            keys = re.findall(r"'([a-z_0-9]+)'\s*=>", flat)

            # updateOrCreate's first argument is the match key, which may be a
            # primary key that is intentionally not fillable.
            ignore = {"password_confirmation", "remember", "id"}
            bogus = [
                k for k in keys
                if k not in fillable and k not in ignore and not k.startswith("_")
            ]
            if bogus:
                problems.append(
                    f"{seeder}: {model}::create/updateOrCreate writes non-fillable keys: "
                    + ", ".join(sorted(set(bogus)))
                )


def check_service_calls(services: dict[str, set[str]]) -> None:
    """Any $this->service->method() in a seeder must exist on that service."""
    for path in SEEDERS.glob("*.php"):
        src = read(path)
        seeder = path.name

        # Map constructor-promoted property names to service classes
        ctor = re.search(r"public function __construct\((.*?)\)\s*\{", src, re.S)
        if not ctor:
            continue

        prop_to_service = {}
        for pm in re.finditer(r"(?:protected|private|public)\s+([A-Z][A-Za-z]+)\s+\$([a-zA-Z_]+)", ctor.group(1)):
            prop_to_service[pm.group(2)] = pm.group(1)

        for pm in re.finditer(r"\$this->([a-zA-Z_]+)->([a-zA-Z_]+)\s*\(", src):
            prop, method = pm.group(1), pm.group(2)
            service = prop_to_service.get(prop)
            if service is None or service not in services:
                continue
            if method not in services[service]:
                problems.append(
                    f"{seeder}: calls ${prop}->{method}() but {service} has no such public method"
                )


def check_enum_usage() -> None:
    """Enum cases referenced in seeders must exist in the enum file."""
    enum_dir = ROOT / "app" / "Enums"
    for path in SEEDERS.glob("*.php"):
        src = read(path)
        for m in re.finditer(r"\b([A-Z][A-Za-z]+)::([A-Z][A-Za-z]+)\b", src):
            enum, case = m.group(1), m.group(2)
            enum_file = enum_dir / f"{enum}.php"
            if not enum_file.exists():
                continue
            enum_src = read(enum_file)
            if not re.search(rf"case\s+{case}\b", enum_src):
                problems.append(f"{path.name}: {enum}::{case} is not a case on the enum")


def check_methods_exist(relations: dict[str, set[str]]) -> None:
    """Chained relation calls in seeders must exist on the model."""
    # Only check the most load-bearing eager loads
    watched = {
        "StudentProfile": ["activeSupervisions", "supervisionAssignments", "projects", "user", "finalGrades"],
        "SupervisorProfile": ["user", "supervisionAssignments", "activeSupervisions", "students", "expertiseAreas", "hasCapacity", "label"],
        "SupervisionAssignment": ["supervisorProfile", "studentProfile", "end", "isPrimary"],
        "Project": ["students", "milestones", "evaluations", "finalGrades", "archivedRecord", "leaderboardEntries", "milestoneProgressPercent", "nextCode", "primaryGrade"],
        "Milestone": ["project", "files", "events", "templateItem", "reviewer"],
        "Evaluation": ["scores", "assessor", "project", "rubricTemplate"],
        "FinalGrade": ["project", "studentProfile", "isReleased", "bandFor", "qualifiesForPublication"],
        "Leaderboard": ["entries", "visibleEntries", "isPublished", "publish", "unpublish", "publicPath"],
        "LeaderboardEntry": ["project", "finalGrade", "medal", "rankSuffix", "shortAbstract"],
        "LeaderboardSetting": ["current", "isPubliclyAvailable"],
        "RubricTemplate": ["components", "criteria", "snapshot", "resolveFor", "weightsBalance"],
        "RubricComponent": ["criteria", "template"],
        "RubricCriterion": ["component", "descriptorFor"],
        "MilestoneTemplate": ["items", "resolveFor", "totalWeight", "weightsBalance"],
        "MilestoneTemplateItem": ["template", "milestones"],
        "ExaminerAssignment": [],
        "ArchivedProject": ["project"],
    }

    for model, methods in watched.items():
        actual = relations.get(model)
        if actual is None:
            problems.append(f"model {model} not found in app/Models")
            continue
        for method in methods:
            if method not in actual:
                problems.append(f"{model} has no public method '{method}' but a seeder relies on it")


def main() -> int:
    tables = schema_columns()
    services = declared_service_methods()
    models = model_relations()

    print(f"Tables discovered in migrations: {len(tables)}")
    print(f"Models discovered: {len(models)}")
    print(f"Services discovered: {len(services)}")
    print()

    check_fillable_against_schema(tables)
    check_seeder_writes(tables)
    check_service_calls(services)
    check_enum_usage()
    check_methods_exist(models)

    if notes:
        print("Notes:")
        for n in notes:
            print(f"  - {n}")
        print()

    if problems:
        print(f"{len(problems)} problem(s):")
        for p in problems:
            print(f"  ! {p}")
        return 1

    print("All seeder/schema/service/enum references consistent.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
