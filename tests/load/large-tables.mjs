/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

// A large file cache and binding table, written straight into the database:
// storages of made-up accounts, each with folders of files, every so many
// of them a pad with its row. The pads' ids spread over the whole file cache,
// as an instance's do that has made pads for years; some rows lose their
// file, as vanished files do. No Etherpad pad stands behind these rows, and
// no account or mount behind their storages, so what resolves a node or
// asks oc_mounts sees none of them: measure only what reads the tables -
// the admin's consistency check and pending pad check, an account's
// deletion, and the walk of a folder deleted (trash-folder.mjs) - and not
// a trash or a restore on these rows.
//
//   node tests/load/large-tables.mjs fill --storages 1000 --files 2000
//   node tests/load/large-tables.mjs measure
//   node tests/load/large-tables.mjs clear

import { writeFileSync } from 'node:fs'
import { parseArgs } from 'node:util'
import { appApi, compose, loadTarget, sqlScript, sqlValue, withPlans } from './lib.mjs'

const { positionals: [command], values: args } = parseArgs({
	allowPositionals: true,
	options: {
		storages: { type: 'string', default: '1000' },
		files: { type: 'string', default: '2000' },
		folders: { type: 'string', default: '20' },
		'pad-every': { type: 'string', default: '20' },
		vanished: { type: 'string', default: '1000' },
		out: { type: 'string' },
	},
})
const prefix = 'home::load-user-'
const ours = `select numeric_id from oc_storages where id like '${prefix}%'`

function fill() {
	const [storages, files, folders, padEvery, vanished] = ['storages', 'files', 'folders', 'pad-every', 'vanished'].map((name) => Number(args[name]))
	const started = Date.now()
	sqlScript(`
begin;
insert into oc_mimetypes (mimetype) select 'text/plain' where not exists (select 1 from oc_mimetypes where mimetype = 'text/plain');
create temp table m as select
	(select id from oc_mimetypes where mimetype = 'httpd/unix-directory') as dir,
	(select id from oc_mimetypes where mimetype = 'httpd') as dirpart,
	(select id from oc_mimetypes where mimetype = 'text/plain') as txt,
	(select id from oc_mimetypes where mimetype = 'text') as txtpart,
	(select id from oc_mimetypes where mimetype = 'application/x-etherpad-nextcloud') as pad,
	(select id from oc_mimetypes where mimetype = 'application') as padpart,
	extract(epoch from now())::bigint as now;
insert into oc_storages (id, available, last_checked) select '${prefix}' || g, 1, 0 from generate_series(1, ${storages}) g;
create temp table s as ${ours};
insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, mtime, storage_mtime, etag, permissions)
	select s.numeric_id, '', md5(''), -1, '', m.dir, m.dirpart, m.now, m.now, md5(random()::text), 31 from s, m;
insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, mtime, storage_mtime, etag, permissions)
	select f.storage, 'files', md5('files'), f.fileid, 'files', m.dir, m.dirpart, m.now, m.now, md5(random()::text), 31
	from oc_filecache f, m where f.storage in (select numeric_id from s) and f.path = '';
insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, mtime, storage_mtime, etag, permissions)
	select f.storage, 'files/d' || k, md5('files/d' || k), f.fileid, 'd' || k, m.dir, m.dirpart, m.now, m.now, md5(random()::text), 31
	from oc_filecache f, m, generate_series(1, ${folders}) k where f.storage in (select numeric_id from s) and f.path = 'files';
create temp table d as select storage, substr(path, 8)::int as k, fileid from oc_filecache where storage in (select numeric_id from s) and path like 'files/d%';
create index on d (storage, k);
-- In order of storage and file: the pads take ids across the whole range.
insert into oc_filecache (storage, path, path_hash, parent, name, mimetype, mimepart, size, mtime, storage_mtime, etag, permissions)
	select s.numeric_id, x.path, md5(x.path), d.fileid, x.name,
		case when n % ${padEvery} = 0 then m.pad else m.txt end, case when n % ${padEvery} = 0 then m.padpart else m.txtpart end,
		1000, m.now, m.now, md5(random()::text), 27
	from s cross join m cross join generate_series(1, ${files}) n
	join d on d.storage = s.numeric_id and d.k = n % ${folders} + 1
	cross join lateral (select case when n % ${padEvery} = 0 then 'p' || n || '.pad' else 'f' || n || '.txt' end as name) nm
	cross join lateral (select 'files/d' || (n % ${folders} + 1) || '/' || nm.name as path, nm.name) x
	order by s.numeric_id, n;
insert into oc_ep_pad_bindings (file_id, pad_id, access_mode, state, deleted_at, created_at, updated_at)
	select f.fileid, case when f.fileid % 10 = 0 then 'load-' || f.fileid else 'g.load' || lpad(f.fileid::text, 10, '0') || '$pad' end,
		case when f.fileid % 10 = 0 then 'public' else 'protected' end, 'active', null, m.now, m.now
	from oc_filecache f, m where f.storage in (select numeric_id from s) and f.mimetype = m.pad;
-- Vanished: the file cache lost them, the rows stay active.
delete from oc_filecache where fileid in (
	select b.file_id from oc_ep_pad_bindings b where b.pad_id like 'g.load%' or b.pad_id like 'load-%' order by random() limit ${vanished});
commit;
analyze oc_filecache;
analyze oc_ep_pad_bindings;
`)
	return {
		seconds: Math.round((Date.now() - started) / 1000),
		fileCacheRows: Number(sqlValue('select count(*) from oc_filecache')),
		bindings: Number(sqlValue('select count(*) from oc_ep_pad_bindings')),
		vanished: Number(sqlValue('select count(*) from oc_ep_pad_bindings b left join oc_filecache f on f.fileid = b.file_id where f.fileid is null and b.state = \'active\'')),
		sizes: sqlValue("select string_agg(relname || ' ' || pg_size_pretty(pg_total_relation_size(relid)), ', ') from pg_statio_user_tables where relname in ('oc_filecache', 'oc_ep_pad_bindings')"),
	}
}

function clear() {
	sqlScript(`
begin;
create temp table s as ${ours};
delete from oc_ep_pad_bindings where file_id in (select fileid from oc_filecache where storage in (select numeric_id from s)) or pad_id like 'g.load%' or pad_id like 'load-%';
delete from oc_filecache where storage in (select numeric_id from s);
delete from oc_storages where numeric_id in (select numeric_id from s);
commit;
analyze oc_filecache;
analyze oc_ep_pad_bindings;
`)
	return { fileCacheRows: Number(sqlValue('select count(*) from oc_filecache')), bindings: Number(sqlValue('select count(*) from oc_ep_pad_bindings')) }
}

/** Each request timed once without the plans and once with them. */
async function timed(name, call) {
	const plain = await call()
	const explained = await withPlans(call)
	return { name, ms: Math.round(plain.ms), status: plain.status, answer: plain.answer, plans: explained.plans }
}

async function measure() {
	const target = loadTarget()
	const post = (endpoint) => async () => {
		const res = await appApi(target, 'POST', endpoint)
		return { ms: res.ms, status: res.status, answer: Object.fromEntries(Object.entries(res.body).filter(([key]) => /count|checked|settled|ok/.test(key))) }
	}
	const results = [
		await timed('consistency check', post('admin/consistency-check')),
		await timed('pending pad check, nothing pending', post('admin/settle-pending')),
	]

	// An account deleted, with pads of its own or none: its rows are found by
	// a join of the table with its home storage. occ starts Nextcloud for
	// itself, so the difference between the two is the app's. Made anew for
	// each run.
	const deleteAccount = (pads) => async () => {
		const uid = `load-account-${Date.now().toString(36)}`
		const password = `load-${Math.random().toString(36).slice(2)}-pw`
		compose('exec', '-T', '-u', 'www-data', '-e', `OC_PASS=${password}`, 'nextcloud', 'php', 'occ', 'user:add', '--password-from-env', uid)
		const account = { ...target, user: uid, auth: 'Basic ' + Buffer.from(`${uid}:${password}`).toString('base64') }
		for (let i = 0; i < pads; i++) {
			const res = await appApi(account, 'POST', 'pads', { file: `/pad-${i}.pad`, accessMode: 'protected' })
			if (res.status !== 200) {
				throw new Error(`create as ${uid}: ${res.status}`)
			}
		}
		const started = performance.now()
		compose('exec', '-T', '-u', 'www-data', 'nextcloud', 'php', 'occ', 'user:delete', uid)
		return { ms: performance.now() - started, status: 0, answer: { pads } }
	}
	results.push(await timed('account without pads deleted (occ user:delete)', deleteAccount(0)))
	results.push(await timed('account with 10 pads deleted (occ user:delete)', deleteAccount(10)))
	return results
}

const report = command === 'fill' ? fill() : command === 'clear' ? clear() : command === 'measure' ? await measure() : null
if (report === null) {
	throw new Error('fill, measure or clear')
}
console.log(JSON.stringify(report, null, '\t'))
if (args.out) {
	writeFileSync(args.out, JSON.stringify(report, null, '\t'))
}
