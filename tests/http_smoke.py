"""Run against a DISPOSABLE, fresh Kanboard with default admin/admin credentials."""
import http.cookiejar
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

base = sys.argv[1].rstrip('/')
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

def get(path):
    return opener.open(base + path, timeout=20).read().decode()

def post(path, values):
    data = urllib.parse.urlencode(values).encode()
    return opener.open(base + path, data=data, timeout=20).read().decode()

def token(html):
    return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)

login = get('/?controller=AuthController&action=login')
post('/?controller=AuthController&action=check', {'username':'admin', 'password':'admin', 'csrf_token': token(login)})
path = '/?controller=WhisperController&action=index&plugin=Whisper'
save = '/?controller=WhisperController&action=save&plugin=Whisper'
html = get(path)
assert 'Как подключить' in html, html[:300]
try:
    post(save, {'csrf_token': 'invalid'})
    raise AssertionError('Invalid CSRF accepted')
except urllib.error.HTTPError as e:
    assert e.code == 403, e.code
values = {'csrf_token': token(html), 'bot_token': '123:TEST_SECRET', 'api_key':'test-provider-key', 'allowed_users':'42', 'provider':'none','language':'ru','project_id':'0','category_id':'0','column_id':'0','confirm':'1'}
html = post(save, values)
assert 'Настройки сохранены' in html
assert 'TEST_SECRET' not in html and 'test-provider-key' not in html
assert '(сохранён)' in html
values.update(csrf_token=token(html), bot_token='', api_key='')
html = post(save, values)
assert '(сохранён)' in html, 'Empty input should retain keys'
values.update(csrf_token=token(html), clear_bot_token='1', clear_api_key='1')
html = post(save, values)
assert '(сохранён)' not in html, 'Explicit clear should remove keys'
# A failed save used to redirect to stored defaults and erase the whole form.
values = {'csrf_token': token(html), 'enabled':'1', 'bot_token':'123:RETRY_SECRET', 'api_key':'retry-key', 'allowed_users':'42', 'provider':'none', 'language':'en', 'model':'my-model', 'project_id':'0', 'category_id':'0', 'column_id':'0', 'confirm':'1'}
html = post(save, values)
assert 'Выберите проект для задач.' in html
assert '42' in html and 'my-model' in html, 'Failed save must preserve ordinary fields'
assert 'RETRY_SECRET' not in html and 'retry-key' not in html, 'Draft credentials must never appear in HTML'
assert '(в черновике)' in html
html = get(path)
assert '42' in html and '(в черновике)' in html, 'Draft must survive reload'
values.update(csrf_token=token(html), bot_token='', api_key='')
values.pop('enabled')  # Correct the incomplete setup by leaving the bot disabled.
html = post(save, values)
assert 'Настройки сохранены' in html and '(сохранён)' in html, 'Retry must retain secret without retyping'
assert 'RETRY_SECRET' not in html and 'retry-key' not in html
print('PASS: validation error retains fields and secrets for retry without exposing them')
print('PASS: HTTP admin login, settings render/save, CSRF rejection, secret retention and removal')

# A regular account must be granted access and cannot inspect another account.
admin_opener = opener
admin_html = get(path)
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
login = get('/?controller=AuthController&action=login')
post('/?controller=AuthController&action=check', {'username':'member', 'password':'testpass', 'csrf_token':token(login)})
member_opener = opener
try:
    get(path)
    raise AssertionError('Unapproved member gained access')
except urllib.error.HTTPError as e:
    assert e.code == 403
opener = admin_opener
post('/?controller=WhisperController&action=permissions&plugin=Whisper', {'csrf_token':token(admin_html), 'save_permissions':'1', 'users[]':'2'})
opener = member_opener
html = get(path + '&user_id=1')
assert 'Member project' in html and 'Admin-only project' not in html
assert '(сохранён)' not in html and 'my-model' not in html, 'User ID parameter must not expose admin settings'
assert 'Сохранить доступ' not in html
member_values = {'csrf_token':token(html), 'bot_token':'456:MEMBER_SECRET', 'allowed_users':'43', 'provider':'none', 'language':'ru', 'project_id':'1', 'category_id':'0', 'column_id':'0', 'confirm':'1'}
html = post(save + '&user_id=1', member_values)
assert 'Настройки сохранены' in html and 'MEMBER_SECRET' not in html
try:
    post('/?controller=WhisperController&action=permissions&plugin=Whisper', {'csrf_token':token(html), 'save_permissions':'1', 'users[]':'3'})
    raise AssertionError('Member changed access permissions')
except urllib.error.HTTPError as e:
    assert e.code == 403
member_values.update(csrf_token=token(html), bot_token='', project_id='2')
html = post(save, member_values)
assert 'Нет права создавать задачи' in html
opener = admin_opener
html = get(path)
assert 'my-model' in html, 'Member save must not overwrite admin configuration'
post('/?controller=WhisperController&action=permissions&plugin=Whisper', {'csrf_token':token(html), 'save_permissions':'1'})
opener = member_opener
try:
    get(path)
    raise AssertionError('Revoked member retained access')
except urllib.error.HTTPError as e:
    assert e.code == 403
print('PASS: HTTP grants, personal settings isolation, project filtering, forged project and permission changes rejected, access revocation')

# English account gets an English form, manual and validation errors.
opener = admin_opener
html = get(path)
post('/?controller=WhisperController&action=permissions&plugin=Whisper', {'csrf_token':token(html), 'save_permissions':'1', 'users[]':'3'})
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
login = get('/?controller=AuthController&action=login')
post('/?controller=AuthController&action=check', {'username':'outsider', 'password':'testpass', 'csrf_token':token(login)})
html = get(path)
assert 'Setup instructions' in html and 'Save and refresh models' in html
assert 'Speech language' in html and 'Default project' in html
assert 'Как подключить' not in html and 'Сохранить доступ' not in html
assert '<select id="model" name="model"' in html
html = post(save, {'csrf_token':token(html), 'enabled':'1', 'bot_token':'789:ENGLISH', 'allowed_users':'44', 'provider':'none', 'language':'en', 'project_id':'0'})
assert 'Choose a project for tasks.' in html and 'Settings have not been saved:' in html
opener = admin_opener
html = get(path)
assert 'Как подключить' in html and 'Сохранить и обновить модели' in html
assert 'Speech language' not in html
print('PASS: RU/EN settings, manuals, model selector, refresh button and validation follow each profile language')

# Personal prompt editing, safe HTML rendering, and restoring the default.
custom_prompt = '<script>alert("prompt")</script> Use short bullets.'
form = {'csrf_token': token(html), 'provider':'none', 'language':'ru', 'project_id':'1', 'category_id':'0', 'column_id':'0', 'normalization_prompt': custom_prompt}
html = post(save, form)
assert 'Настройки сохранены' in html and '&lt;script&gt;' in html and custom_prompt not in html
html = get(path)
assert 'Use short bullets.' in html and html.count('https://t.me/izzypizzy_seo') == 1
form.update(csrf_token=token(html), reset_prompt='1')
html = post(save, form)
assert 'Convert the user dictation' in html and 'Use short bullets.' not in html
print('PASS: editable personal prompt escapes HTML, persists, and resets to default')
