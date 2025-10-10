'''
post processing for chunk texts
'''

import re

def sanitize(text: str) -> str:
    text = re.sub(r'\n{2,}', r'\n', text)
    return text

def concatenate_roles(msgs: str) -> str:
    """join consecutive messages of a same role in a message, if not separated by TS"""
    sep = '\n'
    lines = msgs.strip().splitlines()
    out = []
    buffer_prefix, buffer_parts, last_role = None, [], None

    ts_role_pat = re.compile(r'^\[(\d{6}\w{3} \d{2}:\d{2})\]\s*(Mel|Euno):\s*(.*)')
    ts_only_pat = re.compile(r'^\[\d{6}\w{3} \d{2}:\d{2}\]\s*$')
    role_pat = re.compile(r'^(Mel|Euno):\s*(.*)')

    def flush():
        nonlocal buffer_prefix, buffer_parts, last_role
        if buffer_prefix is not None:
            out.append(f"{buffer_prefix} {sep.join(p for p in buffer_parts if p)}".rstrip())
            buffer_prefix, buffer_parts, last_role = None, [], None

    for line in lines:
        m = ts_role_pat.match(line)
        if m:
            flush()
            ts, role, content = m.groups()
            buffer_prefix, buffer_parts, last_role = f'[{ts}] {role}:', [content], role
            continue

        if ts_only_pat.match(line):
            flush()
            out.append(line)
            last_role = None
            continue

        m = role_pat.match(line)
        if m:
            role, content = m.groups()
            if role == last_role and buffer_prefix is not None:
                buffer_parts.append(content)
            else:
                flush()
                buffer_prefix, buffer_parts, last_role = f'{role}:', [content], role
            continue

        if buffer_prefix is not None:
            buffer_parts.append(line)
        else:
            out.append(line)

    flush()
    return '\n'.join(out)

def post_process_text(msgs: str) -> str:
    msgs = sanitize(msgs)
    msgs = concatenate_roles(msgs)
    return msgs

if __name__ == '__main__':
    pass