from pathlib import Path
import importlib.util
spec = importlib.util.spec_from_file_location('metrics', Path(__file__).parents[1] / 'tools/telemetry_metrics.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
now = 2000000000

def event(eid, kind, data, age=0, instance='a'):
    return dict(id=eid, type=kind, data=data, collected_at=now-age, installation_id=instance, mautic='6.0.9', manager_version='0.1.0')

events = [event('1', 'manager_registered', {}), event('2', 'plugin_installed', {'plugin':'p','version':'1.0.0'}), event('3', 'plugin_updated', {'plugin':'p','version':'1.0.1'}), event('4', 'inventory_snapshot', {'plugins':[{'id':'p','version':'1.0.1'}]}), event('5', 'inventory_snapshot', {'plugins':[{'id':'p','version':'1.0.0'}]}, 31*86400, 'silent')]
events.append(events[2])
a = module.metrics(events, now)
assert a['manager']['active_30d'] == 1
assert a['manager']['unconfirmed_after_30d'] == 1
assert a['plugins']['p']['observed_active_instances_30d'] == 1
assert a['plugins']['p']['cumulative_first_observed_instances'] == 2
assert a['plugins']['p']['successful_operations'] == 2
assert a['plugins']['p']['successful_updates'] == 1
assert a['plugins']['p']['observed_removals'] == 0
events.append(event('6','inventory_snapshot', {'plugins':[]}))
a = module.metrics(events, now)
assert a['plugins']['p']['observed_active_instances_30d'] == 0
assert a['plugins']['p']['observed_removals'] == 0
print('{"suite":"telemetry-metrics","assertions":9,"status":"passed"}')
