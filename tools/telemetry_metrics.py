#!/usr/bin/env python3
"""Reference reducer for consented, exported events. No server and no network I/O."""
import argparse
import collections
import json
import time


def metrics(events, now):
    unique = {event['id']: event for event in events}
    registered = set()
    latest = {}
    first = collections.defaultdict(set)
    counts = collections.defaultdict(collections.Counter)
    for event in sorted(unique.values(), key=lambda e: (e['collected_at'], e['id'])):
        at, instance, kind = event['collected_at'], event['installation_id'], event['type']
        if at > now:
            continue
        data = event['data']
        if kind == 'manager_registered':
            registered.add(instance)
        if kind == 'inventory_snapshot':
            latest[instance] = event
            for plugin in data['plugins']:
                first[plugin['id']].add(instance)
        plugin = data.get('plugin')
        if plugin:
            if kind in ('plugin_detected', 'plugin_installed'):
                first[plugin].add(instance)
            counts[plugin][kind] += 1
    recent = {instance: event for instance, event in latest.items() if event['collected_at'] >= now - 30 * 86400}
    equipped = collections.defaultdict(list)
    for instance, event in recent.items():
        for plugin in event['data']['plugins']:
            equipped[plugin['id']].append((instance, plugin['version'], event['mautic']))
    plugins = {}
    for plugin in sorted(set(first) | set(counts) | set(equipped)):
        count = counts[plugin]
        installs, updates = count['plugin_installed'], count['plugin_updated']
        failures = count['plugin_install_failed'] + count['plugin_update_failed']
        observed = equipped[plugin]
        plugins[plugin] = {
            'observed_active_instances_30d': len({row[0] for row in observed}),
            'cumulative_first_observed_instances': len(first[plugin]),
            'successful_installations': installs,
            'successful_updates': updates,
            'successful_operations': installs + updates,
            'observed_removals': count['plugin_removed'],
            'failure_rate_observed': failures / (installs + updates + failures) if installs + updates + failures else None,
            'versions': dict(collections.Counter(row[1] for row in observed)),
            'mautic_versions': dict(collections.Counter(row[2] for row in observed)),
        }
    return {
        'scope': 'consented observations only; silence never implies uninstallation',
        'at': now,
        'manager': {
            'cumulative_registered_installations': len(registered),
            'active_7d': sum(event['collected_at'] >= now - 7 * 86400 for event in recent.values()),
            'active_30d': len(recent),
            'unconfirmed_after_30d': len(latest) - len(recent),
            'mautic_versions': dict(collections.Counter(event['mautic'] for event in recent.values())),
            'manager_versions': dict(collections.Counter(event['manager_version'] for event in recent.values())),
        },
        'plugins': plugins,
    }


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('jsonl')
    parser.add_argument('--at', type=int, default=int(time.time()))
    args = parser.parse_args()
    with open(args.jsonl, encoding='utf-8') as source:
        print(json.dumps(metrics([json.loads(line) for line in source if line.strip()], args.at), indent=2))
