{{/*
Names and labels
*/}}
{{- define "queryproxy.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "queryproxy.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "queryproxy.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "queryproxy.labels" -}}
helm.sh/chart: {{ include "queryproxy.chart" . }}
app.kubernetes.io/name: {{ include "queryproxy.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{/* Selector labels of one role: dict "ctx" . "component" "web" */}}
{{- define "queryproxy.selectorLabels" -}}
app.kubernetes.io/name: {{ include "queryproxy.name" .ctx }}
app.kubernetes.io/instance: {{ .ctx.Release.Name }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{- define "queryproxy.serviceAccountName" -}}
{{- if .Values.serviceAccount.create -}}
{{- default (include "queryproxy.fullname" .) .Values.serviceAccount.name -}}
{{- else -}}
{{- default "default" .Values.serviceAccount.name -}}
{{- end -}}
{{- end -}}

{{- define "queryproxy.secretName" -}}
{{- default (include "queryproxy.fullname" .) .Values.secrets.existingSecret -}}
{{- end -}}

{{- define "queryproxy.claimName" -}}
{{- default (include "queryproxy.fullname" .) .Values.persistence.existingClaim -}}
{{- end -}}

{{- define "queryproxy.image" -}}
{{- printf "%s:%s" .Values.image.repository (default .Chart.AppVersion .Values.image.tag) -}}
{{- end -}}

{{/* "true" when the application database is SQLite (one pod runs every role). */}}
{{- define "queryproxy.isSqlite" -}}
{{- eq .Values.database.connection "sqlite" -}}
{{- end -}}

{{/*
"true" when the migration Job exists. It is a pre-install/pre-upgrade hook that
runs before the release's own resources are created; see migrationSecretName
for how it gets its credentials.
*/}}
{{- define "queryproxy.usesMigrationHook" -}}
{{- and (ne (include "queryproxy.isSqlite" .) "true") .Values.migration.enabled -}}
{{- end -}}

{{/*
Data of the chart-managed Secret, as a YAML map of base64 values. Computed once
per render (cached on .Values) so that the Secret, its hook copy and the pod
checksum all see the same generated APP_KEY. APP_KEY: secrets.appKey, else the
one already in the cluster (lookup), else a new random one.
*/}}
{{- define "queryproxy.secretData" -}}
{{- if not (hasKey .Values "_secretDataCache") -}}
{{- $existing := lookup "v1" "Secret" .Release.Namespace (include "queryproxy.secretName" .) -}}
{{- $data := dict -}}
{{- range $e := fromYamlArray (include "queryproxy.secretSpec" (dict "ctx" . "role" "web")) -}}
{{- if eq $e.field "appKey" -}}
{{- $kept := dig "data" $e.key "" $existing -}}
{{- if $.Values.secrets.appKey -}}
{{- $_ := set $data $e.key ($.Values.secrets.appKey | b64enc) -}}
{{- else if $kept -}}
{{- $_ := set $data $e.key $kept -}}
{{- else -}}
{{- $_ := set $data $e.key (printf "base64:%s" (randBytes 32) | b64enc) -}}
{{- end -}}
{{- else -}}
{{- $_ := set $data $e.key (index $.Values.secrets $e.field | toString | b64enc) -}}
{{- end -}}
{{- end -}}
{{- $_ := set .Values "_secretDataCache" (toYaml $data) -}}
{{- end -}}
{{- get .Values "_secretDataCache" -}}
{{- end -}}

{{/*
Secret the migration Job reads. A hook runs before the release's own resources
exist, so with a chart-managed Secret the Job gets a hook-only copy
(<fullname>-migrate, created just before it and removed after it). The
ConfigMap and ServiceAccount stay ordinary release resources: the Job gets the
same settings inline (queryproxy.configData) and runs as the default account.
*/}}
{{- define "queryproxy.migrationSecretName" -}}
{{- if .Values.secrets.existingSecret -}}
{{- .Values.secrets.existingSecret -}}
{{- else -}}
{{- printf "%s-migrate" (include "queryproxy.fullname" .) | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}

{{/*
TRUSTED_HOSTS: the user's list plus the Service's in-cluster names, so clients
inside the cluster (Prometheus, other workloads) can use them as Host.
*/}}
{{- define "queryproxy.trustedHosts" -}}
{{- $fullname := include "queryproxy.fullname" . -}}
{{- $hosts := list $fullname (printf "%s.%s" $fullname .Release.Namespace) (printf "%s.%s.svc" $fullname .Release.Namespace) (printf "%s.%s.svc.cluster.local" $fullname .Release.Namespace) -}}
{{- if .Values.config.trustedHosts -}}
{{- $hosts = concat (list .Values.config.trustedHosts) $hosts -}}
{{- end -}}
{{- join "," $hosts -}}
{{- end -}}

{{/* The ConfigMap data as a YAML map; the migration Job renders the same. */}}
{{- define "queryproxy.configData" -}}
{{- $v := .Values -}}
{{- $sqlite := eq (include "queryproxy.isSqlite" .) "true" -}}
{{- $d := dict
    "APP_ENV" "production"
    "APP_DEBUG" "false"
    "APP_URL" $v.config.appUrl
    "LOG_CHANNEL" "stderr"
    "LOG_LEVEL" $v.config.logLevel
    "SESSION_SECURE_COOKIE" (toString $v.config.sessionSecureCookie)
    "QUERYPROXY_EXECUTION_TIMEOUT" (toString (int $v.config.executionTimeout))
    "QUERYPROXY_WORKER_PROCESSES" (toString (int $v.config.workerProcesses))
    "QUERYPROXY_RESULT_DISK" $v.results.disk
    "QUERYPROXY_RESULT_TTL_DAYS" (toString (int $v.config.resultTtlDays))
    "TRUSTED_HOSTS" (include "queryproxy.trustedHosts" .)
    "DB_CONNECTION" $v.database.connection -}}
{{- if $v.config.trustedProxies -}}{{- $_ := set $d "TRUSTED_PROXIES" $v.config.trustedProxies -}}{{- end -}}
{{- if not $sqlite -}}
{{- $_ := set $d "DB_HOST" $v.database.host -}}
{{- if $v.database.port -}}{{- $_ := set $d "DB_PORT" (toString $v.database.port) -}}{{- end -}}
{{- $_ := set $d "DB_DATABASE" $v.database.name -}}
{{- $_ := set $d "DB_USERNAME" $v.database.username -}}
{{- if and (eq $v.database.connection "pgsql") $v.database.sslMode -}}{{- $_ := set $d "DB_SSLMODE" $v.database.sslMode -}}{{- end -}}
{{- end -}}
{{- if $v.redis.enabled -}}
{{- $_ := set $d "REDIS_CLIENT" $v.redis.client -}}
{{- $_ := set $d "REDIS_HOST" $v.redis.host -}}
{{- $_ := set $d "REDIS_PORT" (toString $v.redis.port) -}}
{{- if $v.redis.useForCache -}}{{- $_ := set $d "CACHE_STORE" "redis" -}}{{- end -}}
{{- if $v.redis.useForQueue -}}{{- $_ := set $d "QUEUE_CONNECTION" "redis" -}}{{- end -}}
{{- if $v.redis.useForSessions -}}{{- $_ := set $d "SESSION_DRIVER" "redis" -}}{{- end -}}
{{- end -}}
{{- if eq $v.results.disk "s3" -}}
{{- $_ := set $d "AWS_BUCKET" $v.results.s3.bucket -}}
{{- $_ := set $d "AWS_DEFAULT_REGION" $v.results.s3.region -}}
{{- $_ := set $d "AWS_USE_PATH_STYLE_ENDPOINT" (toString $v.results.s3.pathStyle) -}}
{{- if $v.results.s3.endpoint -}}{{- $_ := set $d "AWS_ENDPOINT" $v.results.s3.endpoint -}}{{- end -}}
{{- end -}}
{{- range $name, $value := $v.config.extraEnv -}}
{{- $_ := set $d $name (toString $value) -}}
{{- end -}}
{{- toYaml $d -}}
{{- end -}}

{{/* Host header the probes send: TrustHosts only accepts APP_URL's host. */}}
{{- define "queryproxy.probeHost" -}}
{{- get (urlParse .Values.config.appUrl) "host" -}}
{{- end -}}

{{/* Seconds a worker pod gets to finish a running query (stopwaitsecs is +20). */}}
{{- define "queryproxy.workerGracePeriod" -}}
{{- add (int .Values.config.executionTimeout) 30 -}}
{{- end -}}

{{/*
Secret entries the given role needs, as a YAML list of
{env, key, field, file}: env is the variable the application reads, key the
name inside the Secret, field the chart value that fills a chart-managed
Secret, file whether NAME_FILE is supported (docker/secret-files.sh).
Argument: dict "ctx" . "role" "web|worker|scheduler|migrate".
*/}}
{{- define "queryproxy.secretSpec" -}}
{{- $v := .ctx.Values -}}
{{- $k := $v.secrets.keys -}}
{{- $list := list (dict "env" "APP_KEY" "key" $k.appKey "field" "appKey" "file" true) -}}
{{- if ne $v.database.connection "sqlite" -}}
{{- $list = append $list (dict "env" "DB_PASSWORD" "key" $k.dbPassword "field" "dbPassword" "file" true) -}}
{{- end -}}
{{- if and $v.redis.enabled $v.redis.auth -}}
{{- $list = append $list (dict "env" "REDIS_PASSWORD" "key" $k.redisPassword "field" "redisPassword" "file" true) -}}
{{- end -}}
{{- if and $v.metrics.enabled (ne .role "migrate") -}}
{{- $list = append $list (dict "env" "QUERYPROXY_METRICS_TOKEN" "key" $k.metricsToken "field" "metricsToken" "file" true) -}}
{{- end -}}
{{- if and $v.admin.email (eq .role "web") -}}
{{- $list = append $list (dict "env" "QUERYPROXY_ADMIN_PASSWORD" "key" $k.adminPassword "field" "adminPassword" "file" true) -}}
{{- end -}}
{{- if and (eq $v.results.disk "s3") $v.results.s3.useSecretCredentials -}}
{{- $list = append $list (dict "env" "AWS_ACCESS_KEY_ID" "key" $k.awsAccessKeyId "field" "awsAccessKeyId" "file" false) -}}
{{- $list = append $list (dict "env" "AWS_SECRET_ACCESS_KEY" "key" $k.awsSecretAccessKey "field" "awsSecretAccessKey" "file" false) -}}
{{- end -}}
{{- toYaml $list -}}
{{- end -}}

{{/* env: entries for the secrets of a role. Argument as secretSpec. */}}
{{- define "queryproxy.secretEnv" -}}
{{- $ctx := .ctx -}}
{{- range $e := fromYamlArray (include "queryproxy.secretSpec" .) -}}
{{- if and $ctx.Values.secrets.mountAsFiles $e.file }}
- name: {{ $e.env }}_FILE
  value: {{ printf "%s/%s" $ctx.Values.secrets.mountPath $e.key | quote }}
{{- else }}
- name: {{ $e.env }}
  valueFrom:
    secretKeyRef:
      name: {{ default (include "queryproxy.secretName" $ctx) $.secret }}
      key: {{ $e.key }}
{{- end }}
{{- end }}
{{- end -}}

{{/* Plain environment that depends on the role. Argument as secretSpec. */}}
{{- define "queryproxy.roleEnv" -}}
{{- $sqlite := eq (include "queryproxy.isSqlite" .ctx) "true" -}}
{{- $runWorker := or (and $sqlite (eq .role "web")) (eq .role "worker") -}}
{{- $runScheduler := or (and $sqlite (eq .role "web")) (eq .role "scheduler") }}
- name: QUERYPROXY_RUN_WORKER
  value: {{ $runWorker | quote }}
- name: QUERYPROXY_RUN_SCHEDULER
  value: {{ $runScheduler | quote }}
{{- if and .ctx.Values.admin.email (eq .role "web") }}
- name: QUERYPROXY_ADMIN_EMAIL
  value: {{ .ctx.Values.admin.email | quote }}
- name: QUERYPROXY_ADMIN_NAME
  value: {{ .ctx.Values.admin.name | quote }}
{{- end }}
{{- end -}}

{{/* Settings that fail the render instead of producing a broken release. */}}
{{- define "queryproxy.validate" -}}
{{- $v := .Values -}}
{{- $sqlite := eq (include "queryproxy.isSqlite" .) "true" -}}
{{- if not (has $v.database.connection (list "sqlite" "pgsql" "mysql" "mariadb")) -}}
{{- fail (printf "database.connection must be sqlite, pgsql, mysql or mariadb, got %q" $v.database.connection) -}}
{{- end -}}
{{- if $sqlite -}}
{{- if gt (int $v.web.replicas) 1 -}}
{{- fail "SQLite cannot be shared between pods: web.replicas must be 1 with database.connection=sqlite. Use pgsql, mysql or mariadb (see values-ha.yaml) to run more than one replica." -}}
{{- end -}}
{{- if $v.worker.autoscaling.enabled -}}
{{- fail "worker.autoscaling needs a separate worker Deployment, which needs a shared application database: set database.connection to pgsql, mysql or mariadb." -}}
{{- end -}}
{{- if not $v.persistence.enabled -}}
{{- fail "database.connection=sqlite needs persistence.enabled=true (or an existing claim); the database would be lost with the pod." -}}
{{- end -}}
{{- else -}}
{{- if not $v.database.host -}}
{{- fail (printf "database.host is required with database.connection=%s" $v.database.connection) -}}
{{- end -}}
{{- if and $v.persistence.enabled (has $v.persistence.accessMode (list "ReadWriteOnce" "ReadWriteOncePod")) -}}
{{- fail "persistence.accessMode must be ReadWriteMany (or persistence.enabled=false) when web, worker and scheduler run as separate pods: a ReadWriteOnce volume cannot be mounted by pods on different nodes." -}}
{{- end -}}
{{- end -}}
{{- if not (has $v.results.disk (list "local" "s3")) -}}
{{- fail (printf "results.disk must be local or s3, got %q" $v.results.disk) -}}
{{- end -}}
{{- if and (eq $v.results.disk "s3") (not $v.results.s3.bucket) -}}
{{- fail "results.s3.bucket is required with results.disk=s3" -}}
{{- end -}}
{{- if and $v.redis.enabled (not $v.redis.host) -}}
{{- fail "redis.host is required with redis.enabled=true" -}}
{{- end -}}
{{- if not $v.secrets.existingSecret -}}
{{- range $e := fromYamlArray (include "queryproxy.secretSpec" (dict "ctx" . "role" "web")) -}}
{{- if and (ne $e.field "appKey") (not (index $v.secrets $e.field)) -}}
{{- fail (printf "secrets.%s is required (it fills %s in the chart-managed Secret); set it or use secrets.existingSecret" $e.field $e.key) -}}
{{- end -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{/*
One Deployment. Argument: dict "ctx" . "role" "web|worker|scheduler"
"cfg" <values of the role> "replicas" <int or "" to leave it to the HPA>
"strategy" "RollingUpdate|Recreate".
*/}}
{{- define "queryproxy.deployment" -}}
{{- $ctx := .ctx -}}
{{- $role := .role -}}
{{- $cfg := .cfg -}}
{{- $sqlite := eq (include "queryproxy.isSqlite" $ctx) "true" -}}
{{- $runsWorker := or (eq $role "worker") (and $sqlite (eq $role "web")) -}}
{{- $spec := dict "ctx" $ctx "role" $role -}}
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "queryproxy.fullname" $ctx }}-{{ $role }}
  labels:
    {{- include "queryproxy.labels" $ctx | nindent 4 }}
    app.kubernetes.io/component: {{ $role }}
spec:
  {{- if ne (toString .replicas) "" }}
  replicas: {{ .replicas }}
  {{- end }}
  strategy:
    type: {{ .strategy }}
  selector:
    matchLabels:
      {{- include "queryproxy.selectorLabels" (dict "ctx" $ctx "component" $role) | nindent 6 }}
  template:
    metadata:
      annotations:
        checksum/config: {{ include (print $ctx.Template.BasePath "/configmap.yaml") $ctx | sha256sum }}
        {{- if not $ctx.Values.secrets.existingSecret }}
        # Hash of the Secret's data, APP_KEY included: the 256-bit key keeps the
        # hash from being brute-forced into a password (an unsalted hash of the
        # values alone could be, by anyone who can read pods). Stable across
        # upgrades because the key is looked up, not regenerated.
        checksum/secret: {{ include "queryproxy.secretData" $ctx | sha256sum }}
        {{- end }}
        {{- with $cfg.podAnnotations }}
        {{- toYaml . | nindent 8 }}
        {{- end }}
      labels:
        {{- include "queryproxy.labels" $ctx | nindent 8 }}
        app.kubernetes.io/component: {{ $role }}
        {{- with $cfg.podLabels }}
        {{- toYaml . | nindent 8 }}
        {{- end }}
    spec:
      {{- with $ctx.Values.imagePullSecrets }}
      imagePullSecrets:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      serviceAccountName: {{ include "queryproxy.serviceAccountName" $ctx }}
      automountServiceAccountToken: false
      securityContext:
        {{- toYaml $ctx.Values.podSecurityContext | nindent 8 }}
      terminationGracePeriodSeconds: {{ if $runsWorker }}{{ include "queryproxy.workerGracePeriod" $ctx }}{{ else }}{{ $ctx.Values.terminationGracePeriodSeconds.web }}{{ end }}
      containers:
        - name: queryproxy
          image: {{ include "queryproxy.image" $ctx | quote }}
          imagePullPolicy: {{ $ctx.Values.image.pullPolicy }}
          securityContext:
            {{- toYaml $ctx.Values.containerSecurityContext | nindent 12 }}
          ports:
            - name: http
              containerPort: 7432
              protocol: TCP
          envFrom:
            - configMapRef:
                name: {{ include "queryproxy.fullname" $ctx }}
            {{- with $ctx.Values.config.extraEnvFrom }}
            {{- toYaml . | nindent 12 }}
            {{- end }}
          env:
            {{- include "queryproxy.roleEnv" $spec | nindent 12 }}
            {{- include "queryproxy.secretEnv" $spec | nindent 12 }}
            {{- range $name, $value := $cfg.extraEnv }}
            - name: {{ $name }}
              value: {{ $value | toString | quote }}
            {{- end }}
          startupProbe:
            httpGet:
              path: /up
              port: http
              httpHeaders:
                - name: Host
                  value: {{ include "queryproxy.probeHost" $ctx | quote }}
            {{- toYaml $ctx.Values.probes.startup | nindent 12 }}
          readinessProbe:
            httpGet:
              path: /up
              port: http
              httpHeaders:
                - name: Host
                  value: {{ include "queryproxy.probeHost" $ctx | quote }}
            {{- toYaml $ctx.Values.probes.readiness | nindent 12 }}
          livenessProbe:
            httpGet:
              path: /up
              port: http
              httpHeaders:
                - name: Host
                  value: {{ include "queryproxy.probeHost" $ctx | quote }}
            {{- toYaml $ctx.Values.probes.liveness | nindent 12 }}
          resources:
            {{- toYaml $cfg.resources | nindent 12 }}
          {{- if or $ctx.Values.persistence.enabled $ctx.Values.secrets.mountAsFiles }}
          volumeMounts:
            {{- if $ctx.Values.persistence.enabled }}
            - name: data
              mountPath: /var/www/html/storage/app
            {{- end }}
            {{- if $ctx.Values.secrets.mountAsFiles }}
            - name: secrets
              mountPath: {{ $ctx.Values.secrets.mountPath }}
              readOnly: true
            {{- end }}
          {{- end }}
      {{- if or $ctx.Values.persistence.enabled $ctx.Values.secrets.mountAsFiles }}
      volumes:
        {{- if $ctx.Values.persistence.enabled }}
        - name: data
          persistentVolumeClaim:
            claimName: {{ include "queryproxy.claimName" $ctx }}
        {{- end }}
        {{- if $ctx.Values.secrets.mountAsFiles }}
        - name: secrets
          secret:
            secretName: {{ include "queryproxy.secretName" $ctx }}
            defaultMode: 0440
            items:
              {{- range $e := fromYamlArray (include "queryproxy.secretSpec" $spec) }}
              {{- if $e.file }}
              - key: {{ $e.key }}
                path: {{ $e.key }}
              {{- end }}
              {{- end }}
        {{- end }}
      {{- end }}
      {{- with $cfg.nodeSelector }}
      nodeSelector:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with $cfg.affinity }}
      affinity:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with $cfg.tolerations }}
      tolerations:
        {{- toYaml . | nindent 8 }}
      {{- end }}
      {{- with $cfg.topologySpreadConstraints }}
      topologySpreadConstraints:
        {{- /* A constraint without a labelSelector counts this role's pods of this release. */}}
        {{- $constraints := list }}
        {{- range $c := . }}
        {{- if not (hasKey $c "labelSelector") }}
        {{- $c = set (deepCopy $c) "labelSelector" (dict "matchLabels" (fromYaml (include "queryproxy.selectorLabels" (dict "ctx" $ctx "component" $role)))) }}
        {{- end }}
        {{- $constraints = append $constraints $c }}
        {{- end }}
        {{- toYaml $constraints | nindent 8 }}
      {{- end }}
{{- end -}}
