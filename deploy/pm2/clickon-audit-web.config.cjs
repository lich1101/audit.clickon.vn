module.exports = {
  apps: [
    {
      name: "clickon-audit-web",
      cwd: "/var/www/clickon-audit/web",
      script: "npm",
      args: "run start -- --hostname 127.0.0.1 --port 3000",
      env: {
        NODE_ENV: "production",
        PORT: "3000",
        HOSTNAME: "127.0.0.1"
      }
    }
  ]
};
