create table if not exists greetings (id serial primary key, message text not null);
insert into greetings (message) values ('hello from t169'), ('bonjour depuis t169'), ('hola desde t169');
create role web_anon nologin;
create role authenticator noinherit login password 't169pass';
grant web_anon to authenticator;
grant usage on schema public to web_anon;
grant select on greetings to web_anon;
