#!/usr/bin/env python3
import argparse
import datetime
import fnmatch
import plistlib
from pathlib import Path


def entitlements(profile, app, now=None):
    now = now or datetime.datetime.now(datetime.timezone.utc).replace(tzinfo=None)
    if profile['ExpirationDate'] <= now:
        raise ValueError('The iOS provisioning profile has expired.')
    values = dict(profile['Entitlements'])
    prefix = profile['ApplicationIdentifierPrefix'][0] + '.'
    identifier = prefix + app['CFBundleIdentifier']
    if not fnmatch.fnmatchcase(identifier, values['application-identifier']):
        raise ValueError('The provisioning profile does not cover app.id.')
    values['application-identifier'] = identifier
    if 'keychain-access-groups' in values:
        values['keychain-access-groups'] = [identifier if value == prefix + '*' else value for value in values['keychain-access-groups']]
    return values


def verify_certificate(profile, certificate):
    if certificate not in profile['DeveloperCertificates']:
        raise ValueError('The signing identity does not match the provisioning profile.')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('profile', type=Path)
    parser.add_argument('--app', type=Path)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--certificate', type=Path)
    args = parser.parse_args()
    profile = plistlib.loads(args.profile.read_bytes())
    try:
        if args.certificate:
            verify_certificate(profile, args.certificate.read_bytes())
        else:
            if not args.app or not args.output:
                parser.error('--app and --output are required to generate entitlements')
            values = entitlements(profile, plistlib.loads(args.app.read_bytes()))
            args.output.write_bytes(plistlib.dumps(values))
    except (ValueError, KeyError, IndexError) as error:
        parser.error(str(error))


if __name__ == '__main__':
    main()
