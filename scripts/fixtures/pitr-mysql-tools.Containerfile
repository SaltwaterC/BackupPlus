ARG MYSQL_IMAGE=docker.io/library/mysql:8.4
FROM ${MYSQL_IMAGE}
COPY ca-bundle.pem /etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem
COPY mysql-community.repo /etc/yum.repos.d/mysql-community.repo
RUN microdnf remove -y mysql-community-server-minimal \
    && microdnf install -y --setopt=install_weak_deps=0 mysql-community-client \
    && microdnf clean all
