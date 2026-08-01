import re
import sys

with open('openapi.yaml', 'r', encoding='utf8') as f:
    content = f.read()

# Split by paths
paths_section = re.search(r'paths:\s*\n(.*?)\ncomponents:', content, re.DOTALL)
if not paths_section:
    # Try alternative split
    paths_section = re.search(r'paths:\s*\n(.*)', content, re.DOTALL)

paths_content = paths_section.group(1)

# Split by individual paths
path_blocks = re.split(r"\n  '(/.*?)':", paths_content)

installations = []
for i in range(1, len(path_blocks), 2):
    path = path_blocks[i]
    body = path_blocks[i+1]
    
    if not path.startswith('/products/{product_id}/installs'):
        continue
    
    # Find methods in this path block
    # Methods are at indentation of 4 spaces
    method_blocks = re.split(r'\n    (get|post|put|delete):', body)
    
    # Path level parameters
    path_params_match = re.search(r'\n    parameters:\s*\n(.*?)(?=\n    [a-z]| \n|$)', body, re.DOTALL)
    path_params = path_params_match.group(1) if path_params_match else ""

    for j in range(1, len(method_blocks), 2):
        method = method_blocks[j]
        m_body = method_blocks[j+1]
        
        if 'Installations' in m_body:
            # Extract operationId
            op_id_match = re.search(r'operationId:\s*(.*)', m_body)
            op_id = op_id_match.group(1).strip() if op_id_match else "unknown"
            
            # Extract summary
            summary_match = re.search(r'summary:\s*\'(.*)\'', m_body)
            summary = summary_match.group(1) if summary_match else ""
            
            # Extract parameters
            m_params_match = re.search(r'parameters:\s*\n(.*?)(?=\n      [a-z]| \n|$)', m_body, re.DOTALL)
            m_params = m_params_match.group(1) if m_params_match else ""
            
            all_params = path_params + "\n" + m_params
            
            # Extract requestBody
            has_req_body = 'requestBody:' in m_body
            
            # Extract response type
            is_binary = 'application/octet-stream' in m_body or '*/*' in m_body
            
            installations.append({
                'path': path,
                'method': method.upper(),
                'operationId': op_id,
                'summary': summary,
                'all_params': all_params,
                'has_req_body': has_req_body,
                'is_binary': is_binary,
                'm_body': m_body
            })

for op in installations:
    path_suffix = op['path'].replace('/products/{product_id}/', '')
    method_name = ''.join(word.capitalize() for word in op['operationId'].replace('installations/', '').replace('-', ' ').split())
    
    # Simple param extraction
    params = re.findall(r'\$ref: \'#/components/parameters/(.*?)\'', op['all_params'])
    # Add inline params if any (less common in this file)
    inline_params = re.findall(r'name:\s*(.*)', op['all_params'])
    
    # Request properties extraction (very simplified)
    req_props = []
    if op['has_req_body']:
        # Look for properties in the m_body or referenced schema
        props_match = re.search(r'properties:\s*\n(.*?)(?=\n      [a-z]| \n|$)', op['m_body'], re.DOTALL)
        if props_match:
            req_props = re.findall(r'(\w+):\s*\{', props_match.group(1))
            if not req_props:
                req_props = re.findall(r'(\w+):\s*\n', props_match.group(1))

    print(f"---")
    print(f"Operation ID: {op['operationId']}")
    print(f"HTTP Method: {op['method']}")
    print(f"Path Suffix: {path_suffix}")
    print(f"Product Method: {method_name}")
    print(f"Path Parameters: {', '.join([p for p in params if p in ['product_id', 'install_id', 'addon_id', 'plan_id', 'clone_id', 'license_id', 'plugin_id', 'theme_id']])}")
    print(f"Query Parameters: {', '.join([p for p in params if p not in ['product_id', 'install_id', 'addon_id', 'plan_id', 'clone_id', 'license_id', 'plugin_id', 'theme_id']])}")
    print(f"Request Body: {'Yes' if op['has_req_body'] else 'No'}")
    if req_props:
        print(f"Request Properties: {', '.join(req_props)}")
    print(f"Response: {'Binary' if op['is_binary'] else 'JSON'}")
