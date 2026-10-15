import os
import subprocess
import re

def parse_po(file_path):
    entries = []
    current_entry = {
        'comments': [],
        'msgid': [],
        'msgstr': []
    }
    state = None
    
    with open(file_path, 'r', encoding='utf-8') as f:
        for line in f:
            line_str = line.strip()
            if line.startswith('#'):
                if state:
                    entries.append(current_entry)
                    current_entry = {'comments': [], 'msgid': [], 'msgstr': []}
                    state = None
                current_entry['comments'].append(line.rstrip('\r\n'))
            elif line.startswith('msgid'):
                if state == 'msgstr':
                    entries.append(current_entry)
                    current_entry = {'comments': [], 'msgid': [], 'msgstr': []}
                state = 'msgid'
                match = re.match(r'^msgid\s+"(.*)"$', line.rstrip('\r\n'))
                if match:
                    current_entry['msgid'].append(match.group(1))
                else:
                    current_entry['msgid'].append("")
            elif line.startswith('msgstr'):
                state = 'msgstr'
                match = re.match(r'^msgstr\s+"(.*)"$', line.rstrip('\r\n'))
                if match:
                    current_entry['msgstr'].append(match.group(1))
                else:
                    current_entry['msgstr'].append("")
            elif line.startswith('"'):
                match = re.match(r'^"(.*)"$', line.rstrip('\r\n'))
                if match:
                    val = match.group(1)
                    if state == 'msgid':
                        current_entry['msgid'].append(val)
                    elif state == 'msgstr':
                        current_entry['msgstr'].append(val)
            elif not line_str:
                if state:
                    entries.append(current_entry)
                    current_entry = {'comments': [], 'msgid': [], 'msgstr': []}
                    state = None
        if state or current_entry['comments']:
            entries.append(current_entry)
            
    return entries

def write_po(file_path, entries):
    with open(file_path, 'w', encoding='utf-8') as f:
        for i, entry in enumerate(entries):
            for comment in entry['comments']:
                f.write(comment + '\n')
            
            if entry['msgid'] or entry['msgstr']:
                if len(entry['msgid']) == 1:
                    f.write(f'msgid "{entry["msgid"][0]}"\n')
                elif len(entry['msgid']) > 1:
                    f.write('msgid ""\n')
                    for part in entry['msgid']:
                        f.write(f'"{part}"\n')
                else:
                    f.write('msgid ""\n')
                    
                if len(entry['msgstr']) == 1:
                    f.write(f'msgstr "{entry["msgstr"][0]}"\n')
                elif len(entry['msgstr']) > 1:
                    f.write('msgstr ""\n')
                    for part in entry['msgstr']:
                        f.write(f'"{part}"\n')
                else:
                    f.write('msgstr ""\n')
                
            if i < len(entries) - 1:
                f.write('\n')

def clean_and_compile(po_path):
    entries = parse_po(po_path)
    cleaned_entries = []
    seen_msgids = set()
    
    # We must treat the first header entry specially or just let it pass
    header_seen = False
    
    for entry in entries:
        msgid_joined = "".join(entry['msgid'])
        msgstr_joined = "".join(entry['msgstr'])
        
        # Check for duplicates
        if msgid_joined == "":
            if not header_seen:
                header_seen = True
                cleaned_entries.append(entry)
            continue
            
        if msgid_joined in seen_msgids:
            # Skip duplicate entry
            continue
            
        seen_msgids.add(msgid_joined)
        
        # Fix mismatch in trailing newlines
        if msgid_joined.endswith('\\n') and not msgstr_joined.endswith('\\n'):
            if entry['msgstr']:
                entry['msgstr'][-1] = entry['msgstr'][-1] + '\\n'
            else:
                entry['msgstr'] = ['\\n']
        elif not msgid_joined.endswith('\\n') and msgstr_joined.endswith('\\n'):
            if entry['msgstr'] and entry['msgstr'][-1].endswith('\\n'):
                entry['msgstr'][-1] = entry['msgstr'][-1][:-2]
                
        cleaned_entries.append(entry)
        
    write_po(po_path, cleaned_entries)
    
    # Compile
    mo_path = po_path[:-3] + '.mo'
    res = subprocess.run(['msgfmt', po_path, '-o', mo_path], capture_output=True, text=True)
    if res.returncode == 0:
        print(f"Successfully compiled {po_path} to {mo_path}", flush=True)
    else:
        print(f"Error compiling {po_path}: {res.stderr}", flush=True)

def main():
    plugin_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '../..'))
    os.chdir(plugin_dir)
    locales_dir = 'locales'
    for file in os.listdir(locales_dir):
        if file.endswith('.po') and not file.endswith('~'):
            po_path = os.path.join(locales_dir, file)
            clean_and_compile(po_path)

if __name__ == '__main__':
    main()

