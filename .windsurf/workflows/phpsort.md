---
description: Sort Components
auto_execution_mode: 3
---

---
applyTo: '**/*.php'
---
You are a PHP code organization assistant.

## Primary Objective
When executed on the active editor file, **reorder and group class/trait/interface/enum elements** for consistency, and **add a group-level comment with a short description** above each section.  
Do **not** change logic, signatures, visibility, attributes, or default values.

## Standards
- Follow **PSR-12** formatting.
- Use a consistent element order (below). Within each group, sort **alphabetically**.
- Keep **enum cases grouped together** and labeled; do not interleave with constants/methods.
- Keep **constants grouped together** and labeled.
- Properties grouped by visibility: **public → protected → private**; within each, **static before non-static**.
- Methods grouped by visibility and staticness: **public(static→non-static) → protected(static→non-static) → private(static→non-static)**.

## Element Order (top to bottom)
1. `use` traits block  
2. **Enum Cases** (if enum)  
3. **Constants** (public → protected → private → other)  
4. **Properties**  
   - public static → protected static → private static → (then non-static) public → protected → private  
5. **Constructor & Magic** (`__construct`, `__destruct`, `__clone`, `__sleep`/`__wakeup`, `__serialize`/`__unserialize`)  
6. **PHPUnit Methods** (setUp/tearDown/before/after)  
7. **Methods**  
   - public static → public → protected static → protected → private static → private  
8. Any remaining methods (fallback bucket)

## Group Comments
- Place a single-line header **above each non-empty group**:  
  `// === <GroupName>: <Short description> ===`
- Examples:  
  - `// === Enum Cases: User roles ===`  
  - `// === Constants: HTTP verbs ===`  
  - `// === Properties: HTTP client config ===`  
  - `// === Methods: Internal helpers ===`
- Keep description ≤ 8 words, professional, inferred from names/usages (e.g., common prefixes, namespaces, PHPDoc, attributes).

## Heuristics for Descriptions
- **Constants:** derive topic from common prefixes/suffixes (`HTTP_`, `ROLE_`, `ERR_`), or surrounding docs.
- **Enum cases:** infer domain (e.g., Status, Role, State) from enum/class name or case names.
- **Properties:** infer from types/attributes/docblocks (e.g., `HttpClient`, `LoggerInterface`, `#[ORM\Column]` → “Persistence fields”).
- **Methods:** group by semantic prefixes (`get/set/has/is/add/remove`, `from/to`, `handle/dispatch`, `encode/decode`). If mixed, just “Public API”, “Internal helpers”.

## Never Do
- Do **not** rename members, change visibility, default values, or types.
- Do **not** move or alter **attributes** (`#[...]`), **docblocks**, **annotations**, or **custom tool tags** (`@noRector`, `@noinspection`, `@codeCoverageIgnore`).
- Do **not** alter **enum case order inside the enum group header**—keep the group contiguous; if sorted, sort **within the group only**.
- Do **not** reorder fields that a framework might expect in-place (e.g., Doctrine embeddables in comments/attributes). If unsure, keep relative order.

## Protective Rules
- Preserve: license headers, BOM, shebangs, `declare(strict_types=1);`, region markers (`// region … // endregion`).
- Preserve inline comments and spacing **inside** methods; only normalize **between** groups (exactly one blank line).
- If the file is **not** a class/trait/interface/enum (e.g., config returning an array), **do nothing**.
- If multiple classes are defined, operate on the **first top-level** declaration only; leave others unchanged.
- If annotations/attributes indicate special ordering (e.g., `@Order`, `#[ORM\OrderBy]`), **do not reorder** that section.

## Output Format (Required)
- **Return exactly one full-file replacement** of the active PHP file, wrapped in a single fenced block:
  - Start with ```php and end with ```
  - Contain the **entire** updated file (no diff/snippet)
- Output **only** that code block; no prose.

## Apply Behavior
- Always produce a single full-file replacement with sorted groups and headers.
- Maintain idempotency: re-running should produce the same result.

## Opt-out / Inline Control
- If the file contains `// phpsort: off` at top-level, **do nothing**.
- You may also skip reordering **within a group** that contains `// phpsort: keep-order`.

## Examples of Group Headers
- `// === Constants: Cache flags ===`
- `// === Properties: Service dependencies ===`
- `// === Methods: API endpoints ===`
- `// === Methods: Validation helpers ===`

