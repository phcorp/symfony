#!/usr/bin/env python3
import datetime
import importlib.util
from pathlib import Path

spec = importlib.util.spec_from_file_location('signing', Path(__file__).resolve().parents[1] / 'signing.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
now = datetime.datetime(2026, 1, 1)
profile = {'ExpirationDate': now + datetime.timedelta(days=1), 'ApplicationIdentifierPrefix': ['TEAM'], 'Entitlements': {'application-identifier': 'TEAM.com.acme.*', 'keychain-access-groups': ['TEAM.*']}, 'DeveloperCertificates': [b'expected certificate']}
values = module.entitlements(profile, {'CFBundleIdentifier': 'com.acme.notes'}, now)
assert values['application-identifier'] == 'TEAM.com.acme.notes'
assert values['keychain-access-groups'] == ['TEAM.com.acme.notes']
assert profile['Entitlements']['application-identifier'] == 'TEAM.com.acme.*'
module.verify_certificate(profile, b'expected certificate')
for call in [lambda: module.entitlements(profile, {'CFBundleIdentifier': 'com.other.app'}, now), lambda: module.entitlements(profile, {'CFBundleIdentifier': 'com.acme.notes'}, now + datetime.timedelta(days=2)), lambda: module.verify_certificate(profile, b'wrong certificate')]:
    try:
        call()
    except ValueError:
        pass
    else:
        raise AssertionError('Invalid signing metadata was accepted')
print('iOS signing metadata tests passed')
