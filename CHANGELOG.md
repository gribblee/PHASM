# Changelog

## [Unreleased]

### Added
- Новый входной файл `phpasm` (замена старого монолитного сценария).
- Модульная архитектура: Lexer/Parser/IR/Codegen/IncludeResolver.
- Поддержка `if`, `while`, `for`.
- Поддержка `printf`.
- Module linker stage:
  - include graph -> module parsing
  - symbol resolution check between modules
  - merge into linked AST/IR before backend.
- Поддержка DLL import:
  - `dll_import(...)`
  - `#[import(...)]`
  - `#[ABI('cdecl'|'stdcall')]` для import-атрибутов.
- Поддержка heap/pointer операций:
  - `heap_alloc`, `heap_alloc_t<T>`, `heap_free`
  - `ptr_get`, `ptr_set`
  - nested типы `hptr<hptr<int>>`.
- Runtime bounds checks для `ptr_get/ptr_set` (границы по size header блока).
- Проверки symbol table:
  - duplicate globals
  - duplicate functions/import aliases
  - conflicts import vs function name.
- Type checking / inference improvements:
  - control-flow definite assignment merge
  - stricter call argument checks
  - IR-level pointer type compatibility.
- Атрибутная типизация:
  - `#[Type('...')]` для параметров
  - `#[ReturnType('...')]` для return типа
  - `#[Generic('int')]` и `#[Generic('T','int')]` в подстановке типов.
- Scalar typed annotation: `typed('type', expr)`.
- IDE stubs: `stubs/phpasm.stub.php`.
- Per-module ASM artifacts in `build/modules/*.asm` during `phpasm` build.

### Changed
- Старая точка входа оставлена в `.deprecated/php_to_fasm.php` как совместимость через `phpasm`.

### Known limitations
- Сборка `.exe` зависит от установленного `fasm` в `PATH`.
- ABI для импортируемых `float`-аргументов/return пока не реализован.
- Нет enforcement безопасности памяти на уровне языка (кроме bounds-check в `ptr_get/ptr_set`).
