// models/Project.js
const mongoose = require('mongoose');

const TeamMemberSchema = new mongoose.Schema({
  name: { type: String, required: true },
  role: { type: String, default: '' },
  email: { type: String, default: '' }
}, { _id: true });

const ProjectSchema = new mongoose.Schema({
  naziv: { type: String, required: true },            
  opis: { type: String, default: '' },                
  cijena: { type: Number, default: 0 },               
  obavljeniPoslovi: { type: String, default: '' },    
  datumPocetka: { type: Date },                       
  datumZavrsetka: { type: Date },                     
  voditelj: { type: mongoose.Schema.Types.ObjectId, ref: 'User', required: true },
  clanoviTima: [{ type: mongoose.Schema.Types.ObjectId, ref: 'User' }],
  archived: { type: Boolean, default: false }
}, { timestamps: true });

module.exports = mongoose.model('Project', ProjectSchema);
