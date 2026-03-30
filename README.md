# phpasm

Минималистичный транслятор `strict_types` PHP-кода в `fasm` ASM под Windows x86 (PE console), с этапом IR.

Пайплайн:
1. `PHP source`
2. `Module resolver (include graph -> modules)`
3. `Tokenizer (token_get_all)`
4. `Parser -> AST per module`
5. `Module linker (symbol resolution + merge)`
6. `IR generator (type-check + symbol checks)`
7. `FASM codegen (.asm)`
8. `fasm -> .exe`

## Быстрый старт

Требования:
- PHP 8+
- FASM в `PATH` (для финальной сборки в `.exe`)

Генерация ASM:

```powershell
php phpasm test/input.php build/output.asm

# опционально указать каталог module-артефактов
php phpasm test/input.php build/output.asm build/modules
```

Полный прогон (IR + ASM + попытка сборки бинарника):

```powershell
./test.bat
```

Если `fasm` не в `PATH`, шаг `asm -> exe` завершится ошибкой окружения.

После запуска `phpasm` также генерируются per-module ASM артефакты (линкерный срез функций) в `build/modules/*.asm`.

## Поддерживаемые возможности

- `declare(strict_types=1)` обязательно
- `include`
- глобальные литералы/массивы
- функции, вызовы с параметрами, `return`
- `if`, `while`, `for`
- `printf`
- DLL import:
  - `dll_import(...)`
  - атрибут `#[import(...)]`
  - calling convention через `dll_import(..., 'cdecl'|'stdcall', ...)` или `#[ABI('cdecl'|'stdcall')]` для `#[import]`
- типы:
  - `int`, `float`, `string`
  - статические массивы (`int[]`, `float[]`, `string[]`)
  - `ptr<T>`, `hptr<T>` включая вложенные (`hptr<hptr<int>>`)
- heap/pointer операции:
  - `heap_alloc`, `heap_alloc_t<T>`, `heap_free`
  - `ptr_get`, `ptr_set`
- атрибутная типизация:
  - `#[Type('...')]` у параметров
  - `#[ReturnType('...')]` у функции
  - `#[Generic('int')]` и `#[Generic('T','int')]` для подстановки в `Type/ReturnType`
- scalar typed annotation:
  - `typed('target_type', expr)`

## Структура проекта

- `phpasm` — входной CLI-скрипт
- `src/PhpAsm/Lexer/Tokenizer.php` — токенизация
- `src/PhpAsm/Parser/*` — парсинг в AST
- `src/PhpAsm/IR/*` — IR + проверки типов/символов
- `src/PhpAsm/Codegen/FasmGenerator.php` — генерация ASM
- `stubs/phpasm.stub.php` — IDE stubs
- `test/input.php` — интеграционный пример
- `test.bat` — прогон генерации и попытка сборки binary

## Типичная отладка

1. Проверка синтаксиса PHP-файлов:

```powershell
php -l src/PhpAsm/Parser/Parser.php
php -l src/PhpAsm/IR/IRGenerator.php
php -l src/PhpAsm/Codegen/FasmGenerator.php
```

2. Проверка генерации ASM:

```powershell
php phpasm test/input.php build/output.asm
```

3. Проверка сборки `.exe`:

```powershell
./test.bat
```
