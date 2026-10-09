{{/* Names */}}
{{- define "snowprint.fullname" -}}
{{- if contains .Chart.Name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name .Chart.Name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}

{{- define "snowprint.labels" -}}
app.kubernetes.io/name: {{ .Chart.Name }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version }}
{{- end -}}

{{- define "snowprint.image" -}}
{{ .Values.image.repository }}:{{ .Values.image.tag | default .Chart.AppVersion }}
{{- end -}}

{{- define "snowprint.secretName" -}}
{{- .Values.database.existingSecret | default (printf "%s-db" (include "snowprint.fullname" .)) -}}
{{- end -}}

{{/* Roles to deploy: name => replicas. */}}
{{- define "snowprint.roles" -}}
{{- if eq .Values.mode "split" -}}
web: {{ .Values.split.web.replicas }}
ingest: {{ .Values.split.ingest.replicas }}
worker: {{ .Values.split.worker.replicas }}
{{- else if eq .Values.mode "single" -}}
all: {{ .Values.single.replicas }}
{{- else -}}
{{- fail "mode must be single or split" -}}
{{- end -}}
{{- end -}}

{{/* Environment shared by every Snowprint container. */}}
{{- define "snowprint.env" -}}
- name: SNOWPRINT_DB_URL
  value: {{ required "database.url is required (see docs/deploy-helm.md)" .Values.database.url | quote }}
- name: SNOWPRINT_DB_USER
  value: {{ .Values.database.user | quote }}
- name: SNOWPRINT_DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ include "snowprint.secretName" . }}
      key: {{ if .Values.database.existingSecret }}{{ .Values.database.existingSecretPasswordKey }}{{ else }}password{{ end }}
- name: SNOWPRINT_PUBLIC_URL
  value: {{ .Values.publicUrl | quote }}
- name: SNOWPRINT_TRUST_PROXY
  value: {{ .Values.trustProxy | quote }}
- name: SNOWPRINT_SECURE_COOKIES
  value: {{ .Values.secureCookies | quote }}
- name: SNOWPRINT_RESPECT_DNT
  value: {{ .Values.respectDnt | quote }}
- name: SNOWPRINT_LOG_CLIENT_IP
  value: {{ .Values.logClientIp | quote }}
- name: SNOWPRINT_REQUEST_TRACE
  value: {{ .Values.requestTrace | quote }}
- name: SNOWPRINT_JSON_PRETTY
  value: {{ .Values.jsonPrettyPrint | quote }}
{{- if .Values.geoip.existingClaim }}
- name: SNOWPRINT_GEOIP_DB
  value: {{ printf "/geo/%s" .Values.geoip.file | quote }}
{{- else if .Values.geoip.download }}
- name: SNOWPRINT_GEOIP_DOWNLOAD
  value: {{ .Values.geoip.download | quote }}
{{- with .Values.geoip.downloadBaseUrl }}
- name: SNOWPRINT_GEOIP_DOWNLOAD_BASE_URL
  value: {{ . | quote }}
{{- end }}
{{- end }}
{{- with .Values.extraEnv }}
{{ toYaml . }}
{{- end }}
{{- end -}}

{{/* Pod security: the image runs as user app (uid 100, gid 101). */}}
{{- define "snowprint.podSecurity" -}}
runAsNonRoot: true
runAsUser: 100
runAsGroup: 101
fsGroup: 101
seccompProfile:
  type: RuntimeDefault
{{- end -}}

{{- define "snowprint.containerSecurity" -}}
allowPrivilegeEscalation: false
readOnlyRootFilesystem: true
capabilities:
  drop: [ALL]
{{- end -}}
