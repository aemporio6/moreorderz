import csv,sys
r=list(csv.DictReader(open('/home/claude/audit/register.csv',encoding='utf-8')))
areas=sys.argv[1].split(','); a=int(sys.argv[2]) if len(sys.argv)>2 else 0; b=int(sys.argv[3]) if len(sys.argv)>3 else 9999
rows=[x for x in r if x['area'] in areas][a:b]
for x in rows:
    print(f"{x['reg_id']}|{x['source_id']}|{x['spec_status'][:3]}|{x['short_text'][:230]}|{x['new_spec_where'][:50]}")
print(len(rows))
