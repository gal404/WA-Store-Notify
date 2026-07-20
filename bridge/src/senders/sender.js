/**
 * ממשק מתאם השליחה (Sender). מאפשר להחליף בעתיד את whatsapp-web.js
 * ב-Green API / WhatsApp Cloud API בלי לגעת ב-worker.
 *
 * @typedef {Object} Sender
 * @property {() => Promise<void>}                         init          אתחול והתחברות
 * @property {() => {state:string, me:object|null, qrDataUrl:string|null}} getState
 * @property {(phoneE164:string) => Promise<boolean>}     verifyNumber  האם המספר רשום בוואטסאפ
 * @property {(phoneE164:string, text:string) => Promise<{waMsgId:string, edited?:boolean}>} sendText
 * @property {(cb:(evt:{phone:string, body:string}) => void) => void}    onIncomingMessage
 * @property {(number:string) => Promise<string>}         requestPairingCode
 * @property {() => Promise<void>}                         logout
 */

module.exports = {};
