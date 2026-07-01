# Documento de Diseño: Pasarela de Captura de Pagos e Idempotencia Global (SaaS)

## 1. Filosofía de la Arquitectura y Fronteras de Responsabilidad

### El Dispositivo Android como "Sensor Tonto" (Sensing Layer)

* **Decisión:** El dispositivo móvil se despoja de toda lógica de evaluación, cálculo o interpretación de datos.
* **El porqué:** El entorno operativo de un teléfono inteligente es intrínsecamente volátil, efímero y hostil para la lógica de negocio. Las optimizaciones agresivas de batería del sistema operativo Android, los microcortes en las redes de datos móviles venezolanas y el riesgo de pérdida o borrado de caché del dispositivo hacen que el almacenamiento móvil no sea fiable como fuente de la verdad. Al convertir la app en un simple transportador de texto crudo, reducimos el consumo de CPU y batería del teléfono, garantizando que el software permanezca ligero y estable en segundo plano durante meses.

### El Servidor Laravel como "Cerebro Único" (Core Layer)

* **Decisión:** Centralizar el parsing, la deduplicación, el registro de auditoría y la toma de decisiones en el Backend.
* **El porqué:** El servidor opera en un entorno controlado (VPS/Cloud) con disponibilidad garantizada, bases de datos con transacciones atómicas y almacenamiento persistente. Centralizar la inteligencia aquí permite auditar fallas de manera inmediata mediante logs unificados, proteger credenciales sensibles de bases de datos y reaccionar a cambios globales en el negocio sin depender de una actualización de software en el teléfono del cliente.

---

## 2. Responsabilidades Críticas de la App Android y su Sustento Técnico

### A. Captura Omnicanal en Tiempo Real (SMS + Alertas Push)

* **Función:** Escuchar simultáneamente los mensajes de texto entrantes de los números emisores del banco y las notificaciones en pantalla de la app bancaria.
* **El porqué:** Las notificaciones push de aplicaciones como la del BNC o BDV dependen de los servicios de Google (Firebase) y del internet del dispositivo; si el internet falla, la notificación push se pierde o se retrasa críticamente. Por el contrario, el SMS viaja por la red celular de voz tradicional (GSM), la cual no requiere datos y es capaz de "despertar" el hardware del teléfono a nivel de sistema operativo de forma inmediata. Al capturar ambos canales en paralelo, el Landlord construye una red de redundancia donde un canal rescata los fallos del otro, asegurando un tiempo de captura cercano al 100%.

### B. Persistencia Inmediata Local (Buffer Preventivo)

* **Función:** Salvar el texto crudo en la base de datos interna del teléfono antes de intentar cualquier petición hacia internet.
* **El porqué:** Si la aplicación intentara enviar la notificación al backend directamente al recibirla, y en ese preciso milisegundo el teléfono pierde cobertura o se apaga por falta de batería, el texto de la notificación bancaria desaparecería de la memoria RAM del teléfono y el pago quedaría en el limbo para siempre. El guardado local previo en el almacenamiento del dispositivo garantiza la durabilidad del dato ante cualquier imprevisto físico o de red.

### C. Garantía de Despacho Asíncrono (Cola de Retención)

* **Función:** Retener los mensajes con estado "Pendiente" y ejecutar reintentos automáticos controlados por el sistema operativo cuando se detecte conexión a la red.
* **El porqué:** Debido a la inestabilidad de las conexiones de datos móviles en el país, el envío directo y lineal (enviar y olvidar) provocaría la pérdida de hasta un 30% de las notificaciones. Una cola local gestionada de forma nativa por el sistema operativo asegura la "consistencia eventual": no importa si el teléfono pasa dos horas sin internet en un apagón; los pagos se acumulan de forma segura en el dispositivo y se despacharán en orden cronológico exacto apenas se restablezca la conectividad.

### D. Depuración por Confirmación Exclusiva (Cierre de Ciclo Seguro)

* **Función:** El teléfono sólo puede eliminar una notificación de su base de datos local cuando el servidor Laravel responda con un código de éxito HTTP explícito.
* **El porqué:** El teléfono no puede asumir que el trabajo está hecho solo por el hecho de haber "completado el envío" de la petición de red. Si el servidor recibe el pago pero sufre un error interno al intentar guardarlo, o si la petición se corta a mitad de camino, el mensaje debe permanecer en el teléfono. Exigir la confirmación final del servidor evita que la aplicación móvil destruya evidencias de transacciones que el backend aún no ha puesto a salvo.

---

## 3. Límites Estrictos de la App Android (Lo que tiene PROHIBIDO hacer)

### No Parsear ni Extraer Información del Texto

* **El porqué:** Los formatos de redacción de los bancos no son estándar y cambian sin previo aviso mediante actualizaciones silenciosas en sus servidores. Si la aplicación Android tuviera la lógica de parsing incrustada en su código, cada cambio sutil en un mensaje bancario rompería el sistema y obligaría al Landlord a compilar, firmar y distribuir una nueva versión de la APK a todos los dispositivos, un proceso que puede tomar días. Si el texto se envía crudo, cualquier cambio de formato del banco se corrige en el backend alterando una expresión regular en segundos, sin tocar los teléfonos.

### No Filtrar Duplicados en el Dispositivo

* **El porqué:** La aplicación Android carece de la visión global del SaaS. Si el Landlord decide colocar dos teléfonos en paralelo para escuchar la misma cuenta (como respaldo físico), o si el usuario desinstala y reinstala la aplicación borrando la base de datos local, el teléfono perderá el historial de lo que ya se envió. Si el dispositivo intentara filtrar duplicados basándose solo en su memoria de corto plazo, dejaría pasar transacciones repetidas en escenarios multi-dispositivo o bloquearía erróneamente reintentos legítimos necesarios para el servidor.

---

## 4. Responsabilidades del Backend Central (Laravel Landlord)

### A. Control de Idempotencia Global (El porqué del Trío Inmutable)

* **Función:** Validar la unicidad de cada transacción utilizando exclusivamente la combinación de tres variables: **Banco Emisor + Número de Referencia + Monto Exacto**.
* **Justificación de los elementos del Hash:**
1. **Por qué NO se incluye el teléfono del emisor:** Bancos como el BNC enmascaran el número de teléfono en las notificaciones push de su app móvil (ej. `0416***9503`), pero lo envían completo en el SMS. Si incluyéramos el teléfono en la llave de validación, el hash del SMS y el hash de la App serían diferentes para el mismo pago, permitiendo que el pago se procese dos veces.
2. **Por qué NO basta con el número de referencia solo:** Aunque teóricamente las referencias bancarias son únicas, en la práctica de la banca nacional existen colisiones. Algunos bancos reinician sus contadores de referencia anualmente o al alcanzar ciertos límites, y existe la probabilidad matemática de que dos bancos distintos emitan la misma referencia numérica en el mismo día.
3. **El porqué de la combinación triple:** Al fusionar el **Banco** (origen del fondo), la **Referencia** (identificador de la transacción) y el **Monto** (valor de la operación), se crea una huella dactilar financiera verdaderamente única a nivel global en tu plataforma. Si el SMS y el push de la App llegan al servidor con segundos de diferencia, el primer mensaje creará el registro; el segundo generará exactamente el mismo hash trilateral, permitiendo al servidor identificar el duplicado al instante, ignorar el reprocesamiento y responder con éxito al teléfono para que limpie su cola.



### B. Fase de Match y Conciliación Asíncrona (Humano vs. Máquina)

* **Función:** Cruzar la corriente de datos automatizada proveniente del teléfono del Landlord con las reclamaciones de pago registradas manualmente por los Tenants en sus respectivos subdominios.
* **El porqué:** El Tenant (cliente) introduce la referencia manualmente en una interfaz web, lo cual es propenso a errores humanos (omitir ceros a la izquierda, transponer números o equivocarse en el monto). Por otro lado, el flujo de Android extrae la información matemática exacta directo del banco. El backend debe actuar como el mediador: almacena temporalmente los datos puros del banco en un estado "Pendiente" y espera a que una orden del Tenant coincida de forma exacta en monto y referencia (o una coincidencia parcial derecha en las referencias) para asegurar que el dinero real en el banco respalde la activación de los servicios del SaaS.

### C. Aislamiento y Activación de Recursos

* **Función:** Una vez validado el pago en la base de datos central, establecer conexión segura con la base de datos aislada del Tenant correspondiente para actualizar su estado.
* **El porqué:** Para cumplir con la seguridad del modelo arquitectónico multitenant, los subdominios no deben tener acceso directo al flujo de notificaciones globales del Landlord. El núcleo central del Landlord es el único autorizado para validar que el dinero entró a su cuenta comercial y, mediante un evento seguro entre bases de datos, otorgar el acceso o renovación de los servicios en la base de datos privada de ese inquilino específico de manera transparente y automatizada.

---

## 5. Mapa del Ciclo de Vida del Dato y Flujo de Control

Este diagrama conceptual ilustra las transiciones de estado del dato y los puntos de decisión críticos donde las justificaciones anteriores entran en juego:

1. **Fase 1: Registro de Intención de Compra:** Interfaz del Tenant.
El Tenant genera una orden en su subdominio para adquirir un servicio. Realiza el Pago Móvil hacia los datos bancarios del Landlord y escribe en la plataforma la referencia que le dio su banco. La orden queda en estado "Esperando Verificación Bancaria".


2. **Fase 2: Captura del Dinero Real:** Periférico Android.
El banco del Landlord procesa los fondos y emite dos señales independientes: una alerta push y un SMS. La app Android captura la primera señal que llegue, la guarda inmediatamente en su almacenamiento interno para evitar pérdidas físicas, y la despacha intacta en formato de texto crudo hacia el servidor.


3. **Fase 3: El Arbitraje de Unicidad:** Ecosistema Laravel (Global).
Laravel recibe el texto. Su parser dinámico aísla el Banco, la Referencia y el Monto. Aplica el filtro de idempotencia global. Si la combinación ya existía (porque el otro canal de redundancia llegó antes), frena el flujo aquí y le dice "OK" al teléfono. Si es una transacción nueva, guarda el registro financiero y autoriza al teléfono a borrar su copia local.


4. **Fase 4: Conciliación y Despacho:** Ecosistema Laravel (Tenant).
El backend central busca en el sistema una orden pendiente del Landlord cuyo monto y referencia coincidan con los datos validados del banco. Al encontrar el Match perfecto, el sistema abre la base de datos aislada de ese Tenant, extiende su suscripción o activa los productos adquiridos, y marca la transacción bancaria como "Conciliada".