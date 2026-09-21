"""A deliberately small parser for Nextcloud's literal $CONFIG array.

No PHP interpreter, eval, includes, constants, interpolation or function calls.
Dynamic configuration must be converted to literal configuration by its operator.
"""
import re

TOKEN = re.compile(r'''\s+|//[^\n]*|\#[^\n]*|/\*.*?\*/|<\?php|\?>|\$CONFIG|=>|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|-?(?:0[xX][a-fA-F0-9]+|0[bB][01]+|0[oO][0-7]+|(?:\d+\.\d*|\.\d+|\d+)(?:[eE][+-]?\d+)?)|[A-Za-z_][A-Za-z0-9_]*|[\[\](),;=]''', re.S)


def parse(text):
    if len(text) > 4 * 1024 * 1024:
        raise ValueError('Configuration too large')
    tokens, pos = [], 0
    while pos < len(text):
        match = TOKEN.match(text, pos)
        if not match:
            raise ValueError('Only literal Nextcloud configuration is supported')
        token = match[0]
        pos = match.end()
        if not token.isspace() and not token.startswith(('//', '#', '/*')):
            tokens.append(token)
    index = 0

    def take(expected=None):
        nonlocal index
        if index >= len(tokens):
            raise ValueError('Incomplete configuration')
        token = tokens[index]
        index += 1
        if expected is not None and token != expected:
            raise ValueError('Nonliteral configuration refused')
        return token

    def value(depth=0):
        if depth > 32:
            raise ValueError('Configuration nesting too deep')
        token = take()
        if token.lower() in ('array', '['):
            end = ')'
            if token.lower() == 'array':
                take('(')
            else:
                end = ']'
            result, next_key = {}, 0
            while index < len(tokens) and tokens[index] != end:
                item = value(depth + 1)
                if index < len(tokens) and tokens[index] == '=>':
                    take('=>')
                    if type(item) not in (str, int):
                        raise ValueError('Invalid array key')
                    key, item = item, value(depth + 1)
                else:
                    key = next_key
                if key in result:
                    raise ValueError('Duplicate configuration key')
                result[key] = item
                if type(key) is int:
                    next_key = max(next_key, key + 1)
                if index < len(tokens) and tokens[index] != end:
                    take(',')
            take(end)
            return result
        if token.startswith("'"):
            return token[1:-1].replace("\\'", "'").replace('\\\\', '\\')
        if token.startswith('"'):
            raw = token[1:-1]
            if '$' in raw or re.search(r'\\(?![\\"nrt])', raw):
                raise ValueError('PHP interpolation/escape refused')
            return re.sub(r'\\([\\"nrt])', lambda m: {'n': '\n', 'r': '\r', 't': '\t'}.get(m[1], m[1]), raw)
        if token.lower() in ('true', 'false', 'null'):
            return {'true': True, 'false': False, 'null': None}[token.lower()]
        if re.fullmatch(r'-?(?:0[xX][a-fA-F0-9]+|0[bB][01]+|0[oO][0-7]+|[0-9]+)', token):
            raw = token.lstrip('-')
            base = 0 if raw.lower().startswith(('0x', '0b', '0o')) else (8 if len(raw) > 1 and raw.startswith('0') else 10)
            number = int(token, base)
            if not -(2 ** 63) <= number < 2 ** 63:
                raise ValueError('PHP integer out of range')
            return number
        if re.fullmatch(r'-?(?:\d+\.\d*|\.\d+|\d+)(?:[eE][+-]?\d+)?', token):
            import math
            number = float(token)
            if not math.isfinite(number):
                raise ValueError('Non-finite PHP number')
            return number
        raise ValueError('Only literal configuration values are supported')

    take('<?php')
    take('$CONFIG')
    take('=')
    result = value()
    take(';')
    if index < len(tokens):
        take('?>')
    if index != len(tokens) or not isinstance(result, dict):
        raise ValueError('Unexpected executable configuration')
    return result


def render(value):
    if isinstance(value, dict):
        return '[' + ',\n'.join(render(k) + ' => ' + render(v) for k, v in value.items()) + ']'
    if isinstance(value, str):
        return "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'"
    if value is True:
        return 'true'
    if value is False:
        return 'false'
    if value is None:
        return 'null'
    if type(value) in (int, float):
        import math
        if isinstance(value, float) and not math.isfinite(value):
            raise ValueError('Non-finite PHP number')
        return repr(value)
    raise ValueError('Unsupported configuration value')
