# TODO / Readiness Checklist

Цель: формально верифицировать готовность компилятора `PHP -> IR -> FASM -> binary` и вести roadmap развития до полноценного AOT.

## Текущий статус

- [x] symbols resolved
- [x] types assigned
- [x] CFG built
- [x] memory model explicit
- [x] ABI defined
- [x] no unresolved generics
- [x] stack frame computed
- [x] all extern functions declared
- [x] per-module asm artifacts generated + linker stage

## Краткий DoD (текущий этап)

- [x] strict typing enforced
- [x] tokenization / parser / IR / backend
- [x] linker (module merge + symbol resolution check)
- [x] type checking/inference базового уровня
- [x] pointer/heap базового уровня
- [x] runtime bounds checks для `ptr_get/ptr_set`
- [x] asm generation
- [ ] стабильная `asm -> binary` сборка во всех окружениях (зависит от `fasm`)
- [ ] float ABI mapping для imported функций

## Основная долгосрочная цель

- [ ] Полноценный AOT-компилятор (UX уровня Go):
  - [ ] `php (frontend) -> IR -> fasm (backend) -> per-module asm + linker -> binary`
  - [ ] production-готовый оптимизатор кода
  - [ ] deterministic/reproducible builds
  - [ ] CLI/HTTP режимы рантайма
  - [ ] GC

## Roadmap (расширенный)

### 1. КРИТИЧНО: IR invariants (formal invariants)

- [ ] `IRValidator::validate(Program $ir): Report`

IR invariants:
- [ ] каждый basic block имеет terminator (`jmp`/`ret`)
- [ ] нет unreachable блоков (или явно помечены)
- [ ] нет use-before-def
- [ ] single definition для значений (или формально фиксируем non-SSA режим)
- [ ] типы согласованы на границах блоков

Control-flow invariants:
- [ ] все пути в non-void функциях возвращают значение
- [ ] нет fallthrough в никуда
- [ ] loops имеют корректные back-edges

Call invariants:
- [ ] аргументы соответствуют ABI
- [ ] стек сбалансирован после `call`
- [ ] return value используется корректно

### 2. Backend readiness (FASM-specific guarantees)

- [ ] Stack discipline:
  - [ ] `esp` invariant соблюден
  - [ ] calling convention (`cdecl`/`stdcall`) соблюден
- [ ] Register safety:
  - [ ] caller-saved / callee-saved контракты соблюдены
- [ ] Section correctness:
  - [ ] `.code` / `.data` / `.idata` корректны
  - [ ] нет invalid cross-section references
- [ ] Import table correctness:
  - [ ] нет дубликатов импортов
  - [ ] extern symbols либо используются, либо удаляются

### 3. Тестирование

- [ ] Snapshot tests (важно):
  - [ ] `input.php -> IR -> ASM` сравнение с golden files
- [ ] Differential testing:
  - [ ] сравнение поведения `PHP execution` vs `compiled binary`
- [ ] Fuzzing:
  - [ ] random subset PHP -> compile -> без падений пайплайна

### 4. Reproducibility

- [ ] Deterministic build: одинаковый input -> byte-identical binary
- [ ] No hidden state:
  - [ ] нет зависимости от времени/случайности
  - [ ] нет нестабильных id/label
- [ ] Stable symbol naming:
  - [ ] deterministic именование меток/символов

### 5. IR как контракт

- [ ] IR spec (минимум):
  - [ ] список node types
  - [ ] поля
  - [ ] invariants
  - [ ] allowed transformations
- [ ] Versioning IR:
  - [ ] `IR v1 -> v2` + правила breaking changes

### 6. Оптимизатор

- [ ] Canonicalization pass:
  - [ ] `a + 0 -> a`
  - [ ] `a * 1 -> a`
- [ ] CFG simplification:
  - [ ] `if (true)` -> inline branch
- [ ] Ограниченный inline expansion:
  - [ ] small functions -> inline
- [ ] Load/store elimination:
  - [ ] `ptr_get(ptr_set(...)) -> value` (без нарушения alias/side-effects)

### 7. Memory model (усиление)

- [ ] Базовый alias analysis:
  - [ ] могут ли два указателя алиасить одну область
- [ ] Lifetime model:
  - [ ] `alloc -> use -> free`
- [ ] Debug mode checks (опционально):
  - [ ] double free detection
  - [ ] use-after-free detection

### 8. Tooling

- [ ] IR/CFG/types dumps:
  - [ ] `--dump-ir`
  - [ ] `--dump-cfg`
  - [ ] `--dump-types`
- [ ] Visual CFG:
  - [ ] IR -> Graphviz
- [ ] Debug mode:
  - [ ] дополнительные runtime checks

### 9. Pipeline checks (автоматизация)

- [ ] Двухфазная валидация:
  - [ ] `AST -> IR -> VALIDATE -> LOWER -> VALIDATE -> ASM`

### 10. Реальные риски

- [ ] ABI drift (несовпадение соглашений вызова)
- [ ] silent miscompile (компилируется, но работает неверно)
- [ ] IR explosion (чрезмерная сложность IR без строгих инвариантов)

### 11. Additional Verification (готовый блок)

- [ ] IR invariants validation (CFG, types, defs/uses)
- [ ] Backend stack/register correctness checks
- [ ] Deterministic build verification (byte-level)
- [ ] Snapshot tests (IR/ASM)
- [ ] Differential execution tests (PHP vs binary)
- [ ] Fuzz testing (parser + IR)
- [ ] Stable symbol naming
- [ ] IR versioning and spec
- [ ] Double validation stage (pre/post lowering)

## Ближайшие приоритеты

### P1
- [ ] `IRValidator::validate` + formal invariants
- [ ] float ABI mapping для imports
- [ ] snapshot + differential tests
- [ ] object-like linker evolution (`public/extrn`, релокации)

### P2
- [ ] fuzzing pipeline
- [ ] deterministic build checks
- [ ] backend register/stack verifier

### P3
- [ ] IR versioning/spec doc
- [ ] canonical optimizer passes + CFG simplification
- [ ] debug memory checks
